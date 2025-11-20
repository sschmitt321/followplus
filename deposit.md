# Tron TRC20 USDT Deposit & Withdrawal System (PHP Backend)

本文件是给后端 / 架构 / Cursor 使用的技术设计文档，场景为：

- 资产：**TRC20 USDT**
- 每个用户：**一个独立 Tron 地址，专门用于充值 USDT**
- 后端技术栈：**PHP + MySQL + 定时任务/Worker**
- 功能范围：
  - 给用户分配充值地址（地址 + 私钥管理）
  - 监听链上充值，自动入账（内部账本）
  - 定时归集 USDT 到平台热钱包
  - 从热钱包给用户提币
  - 私钥与钱包安全设计（含 HD 子钱包数量说明）

---

## 0. 总体设计概览

### 0.1 架构要点

- 每个用户一个 TRON 地址（例如 `Txxx...`），仅用于接收 TRC20 USDT 充值。
- 服务器维护 **地址 + 加密后的私钥** 映射关系，不在代码或日志中泄露明文私钥。
- 后端通过 Tron 节点 / TronGrid API 监听 TRC20 USDT 的 `Transfer` 事件：
  - 当 `to` 等于任一用户充值地址时，记录充值。
  - 等确认数达到阈值（例如 20）后，将充值记入用户内部余额。
- 定时任务对余额超过阈值的用户地址执行**归集**操作：
  - 从“TRX Gas Bank 地址”给这些地址打极少量 TRX 作为 gas。
  - 使用该地址私钥发起 USDT 转账到平台热钱包。
- 用户提币从平台热钱包发起 USDT 转账到目标地址。
- 私钥加密存储；推荐使用单独的钱包服务（Wallet Service）来持有解密逻辑。

---

## 1. 数据库表设计

### 1.1 用户 Tron 钱包表 `user_tron_wallets`

用于记录每个用户对应的 Tron 充值地址及其加密私钥。

```sql
CREATE TABLE user_tron_wallets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    tron_address VARCHAR(64) NOT NULL UNIQUE,
    derivation_index INT UNSIGNED NOT NULL DEFAULT 0,  -- HD 地址 index（未来扩展用）
    encrypted_private_key TEXT NOT NULL,               -- 加密后的私钥
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id)
);
```

说明：

- `tron_address`：用户专属充值地址。
- `derivation_index`：如果未来采用 HD 钱包（主种子 + index 派生），这里存 index；目前可设为 0。
- `encrypted_private_key`：用 AES-256-GCM 或类似算法加密后的私钥字符串。

---

### 1.2 充值记录表 `tron_deposits`

记录所有 TRC20 USDT 链上充值事件，并追踪其确认状态。

```sql
CREATE TABLE tron_deposits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    tron_address VARCHAR(64) NOT NULL,
    txid VARCHAR(128) NOT NULL,
    from_address VARCHAR(64) NOT NULL,
    amount DECIMAL(36, 6) NOT NULL,
    token_symbol VARCHAR(16) NOT NULL,               -- USDT
    confirmations INT UNSIGNED NOT NULL DEFAULT 0,
    required_confirmations INT UNSIGNED NOT NULL DEFAULT 20,
    status ENUM('pending','confirmed','credited','failed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_txid_token (txid, tron_address)
);
```

说明：

- `pending`：已检测到链上事件，但确认数未达到目标。
- `confirmed`：确认数已达到，但尚未入账内部余额。
- `credited`：已完成内部账本的加余额操作。
- `failed`：异常或确认失败等情况。

---

### 1.3 归集记录表 `tron_sweeps`

记录从用户充值地址归集到平台热钱包的操作。

```sql
CREATE TABLE tron_sweeps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    from_address VARCHAR(64) NOT NULL,
    to_address VARCHAR(64) NOT NULL,         -- 热钱包地址
    txid VARCHAR(128) DEFAULT NULL,
    amount DECIMAL(36, 6) NOT NULL,
    status ENUM('created','broadcasted','confirmed','failed') NOT NULL DEFAULT 'created',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

---

### 1.4 提币记录表 `tron_withdrawals`

记录用户从平台提币到外部地址的操作。

```sql
CREATE TABLE tron_withdrawals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    to_address VARCHAR(64) NOT NULL,
    amount DECIMAL(36, 6) NOT NULL,
    fee DECIMAL(36, 6) NOT NULL DEFAULT 0,
    txid VARCHAR(128) DEFAULT NULL,
    status ENUM('created','reviewing','approved','broadcasted','confirmed','rejected','failed')
        NOT NULL DEFAULT 'created',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

---

## 2. 核心业务流程

### 2.1 用户获取充值地址

**触发场景：**

- 用户第一次访问 “充值 USDT（TRC20）” 页。
- 或在账户页面点击 “生成 TRON 充值地址”。

**流程：**

1. 查询 `user_tron_wallets` 表中是否已有 `user_id = 当前用户` 的记录。
2. 若存在，则直接返回 `tron_address`。
3. 若不存在：
   - 调用 Tron 钱包生成逻辑生成新地址和私钥；
   - 加密私钥并存入 `user_tron_wallets`；
   - 将生成的地址返回前端展示。

**示例：`TronWalletService.php` 伪代码**

```php
<?php

namespace App\Services\Tron;

use Illuminate\Support\Facades\DB;

class TronWalletService
{
    public function getOrCreateDepositAddress(int $userId): string
    {
        $wallet = DB::table('user_tron_wallets')->where('user_id', $userId)->first();

        if ($wallet) {
            return $wallet->tron_address;
        }

        // 1. 生成 Tron 地址（调用 tron-php 或其他 SDK）
        $addressData = $this->generateTronAddress();
        $address     = $addressData->address;     // T 开头地址
        $privateKey  = $addressData->privateKey;  // hex 私钥

        // 2. 加密私钥
        $encryptedPk = $this->encryptPrivateKey($privateKey);

        // 3. 写入数据库
        DB::table('user_tron_wallets')->insert([
            'user_id'               => $userId,
            'tron_address'          => $address,
            'derivation_index'      => 0,
            'encrypted_private_key' => $encryptedPk,
        ]);

        return $address;
    }

    private function generateTronAddress()
    {
        // TODO: 封装 tron-php / 自有 SDK 的生成逻辑
        // 例如：
        // $api = new Api(new Client(['base_uri' => 'https://api.trongrid.io']));
        // $trx = new TRX($api);
        // return $trx->generateAddress();
    }

    private function encryptPrivateKey(string $pk): string
    {
        $key    = env('TRON_PK_ENC_KEY');   // 32字节密钥，使用 .env 管理或 KMS
        $cipher = 'aes-256-gcm';
        $iv     = random_bytes(openssl_cipher_iv_length($cipher));
        $tag    = '';

        $ciphertext = openssl_encrypt(
            $pk,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decryptPrivateKey(string $encrypted): string
    {
        $key    = env('TRON_PK_ENC_KEY');
        $cipher = 'aes-256-gcm';

        $data       = base64_decode($encrypted);
        $ivLength   = openssl_cipher_iv_length($cipher);
        $iv         = substr($data, 0, $ivLength);
        $tag        = substr($data, $ivLength, 16);
        $ciphertext = substr($data, $ivLength + 16);

        return openssl_decrypt(
            $ciphertext,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
    }
}
```

---

### 2.2 监听 TRC20 USDT 充值

推荐使用 **Console Command + 定时任务 / Supervisor** 方式运行。

核心思路：

1. 使用 Tron 节点 API 或 TronGrid 的事件接口，定期拉取 USDT 合约最近的 `Transfer` 事件。
2. 过滤 `to` 地址在我们 `user_tron_wallets` 表中的记录。
3. 对每条匹配的事件：
   - 查 `tron_deposits` 判断是否已存在对应 txid+地址记录；
   - 若不存在，插入一条新的 `pending` 充值记录；
4. 另一个定时任务，根据区块高度/confirmations 更新充值状态：
   - 当 `confirmations >= required_confirmations` 时：
     - 将 `status` 标记为 `confirmed`；
     - 完成内部账本加余额 → 标记为 `credited`。

**伪代码轮廓：**

```php
class TronDepositService
{
    public function scanNewDeposits()
    {
        // 1. 从 Tron 节点 / TronGrid 拉取最近一段时间的 USDT Transfer 事件
        $events = $this->tronNodeClient->getUsdtTransferEvents([
            // 可以按 blockNumber / timestamp 分页拉取
        ]);

        foreach ($events as $event) {
            $to = $event['to'];
            $from = $event['from'];
            $amount = $event['amount']; // 需注意精度/decimals
            $txid = $event['txid'];

            // 2. 这个 to 地址是否是我们的充值地址？
            $wallet = DB::table('user_tron_wallets')
                ->where('tron_address', $to)
                ->first();

            if (!$wallet) {
                continue; // 不是我们平台的充值地址
            }

            // 3. 是否已存在？
            $exists = DB::table('tron_deposits')
                ->where('txid', $txid)
                ->where('tron_address', $to)
                ->exists();

            if ($exists) {
                continue;
            }

            // 4. 创建 pending 充值记录
            DB::table('tron_deposits')->insert([
                'user_id'                => $wallet->user_id,
                'tron_address'           => $to,
                'txid'                   => $txid,
                'from_address'           => $from,
                'amount'                 => $amount,
                'token_symbol'           => 'USDT',
                'confirmations'          => 0,
                'required_confirmations' => 20,
                'status'                 => 'pending',
            ]);
        }
    }

    public function updateConfirmationsAndCredit()
    {
        // 1. 取出 pending / confirmed 状态的记录
        $rows = DB::table('tron_deposits')
            ->whereIn('status', ['pending', 'confirmed'])
            ->get();

        foreach ($rows as $row) {
            // 2. 查询当前区块信息/tx确认数
            $conf = $this->tronNodeClient->getConfirmations($row->txid);

            DB::table('tron_deposits')
                ->where('id', $row->id)
                ->update(['confirmations' => $conf]);

            if ($row->status === 'pending' && $conf >= $row->required_confirmations) {
                // 3. 标记为 confirmed
                DB::table('tron_deposits')
                    ->where('id', $row->id)
                    ->update(['status' => 'confirmed']);

                // 4. 执行内部账本加余额逻辑（示例）
                $this->creditUserBalance($row->user_id, $row->amount, $row);

                DB::table('tron_deposits')
                    ->where('id', $row->id)
                    ->update(['status' => 'credited']);
            }
        }
    }

    private function creditUserBalance(int $userId, string $amount, $depositRow)
    {
        // TODO: 调用内部统一账本/余额服务，生成一条“充值入金”流水
    }
}
```

---

### 2.3 归集（Sweep）USDT 到热钱包

归集的目标是：

- 避免大量 USDT 长期散落在用户充值地址里。
- 降低风险，集中管理到热钱包（再部分转冷钱包）。

#### 归集策略建议：

- 设置归集阈值 `MIN_SWEEP_AMOUNT`，例如 50 或 100 USDT。
- 仅当某个地址 USDT 余额 ≥ 阈值时才归集。
- 归集前，确保该地址有足够的 TRX 作为 gas：
  - 如果不足，则从 TRX Gas Bank 地址打少量 TRX（如 1–2 TRX）。
- 使用该用户地址对应的私钥签名 TRC20 `transfer(hot_wallet, amount)` 交易。

**伪代码轮廓：**

```php
class TronSweepService
{
    private float $minSweepAmount = 50.0; // 可配置
    private string $hotWalletAddress = 'Txxxxx...'; // 平台热钱包地址

    public function sweepAll()
    {
        // 1. 获取所有用户 Tron 钱包
        $wallets = DB::table('user_tron_wallets')->get();

        foreach ($wallets as $wallet) {
            $address = $wallet->tron_address;

            // 2. 查询该地址的 USDT 余额
            $usdtBalance = $this->tronUsdtContract->getBalance($address);
            if ($usdtBalance < $this->minSweepAmount) {
                continue;
            }

            // 3. 查询 TRX 余额，不足则从 Gas Bank 转 TRX
            $trxBalance = $this->tronNodeClient->getTrxBalance($address);
            if ($trxBalance < 1.0) {
                $this->sendTrxFromGasBank($address, 2.0);
            }

            // 4. 解密私钥并发送归集交易
            $privateKey = app(TronWalletService::class)
                ->decryptPrivateKey($wallet->encrypted_private_key);

            $txid = $this->tronUsdtContract->transferFromPrivateKey(
                $privateKey,
                $this->hotWalletAddress,
                $usdtBalance
            );

            // 5. 写归集记录
            DB::table('tron_sweeps')->insert([
                'user_id'     => $wallet->user_id,
                'from_address'=> $address,
                'to_address'  => $this->hotWalletAddress,
                'txid'        => $txid,
                'amount'      => $usdtBalance,
                'status'      => 'broadcasted',
            ]);
        }
    }

    private function sendTrxFromGasBank(string $toAddress, float $amount)
    {
        // TODO: 由一个专门的 Gas Bank 地址发起 TRX 转账，用于给用户地址补 gas
    }
}
```

> 说明：  
> - `TronUsdtContract`：一个封装 TRC20 USDT 合约交互的类，需要自己实现。  
> - 归集成本可以通过提高阈值、使用能量租赁、冻结 TRX 等手段控制。

---

### 2.4 提币（Withdraw）

提币从平台热钱包发起，流程上建议：

1. 用户提交提币请求：`to_address + amount`。
2. 后端校验：
   - 用户内部余额是否足够；
   - 是否通过风控（KYC、风控规则、日限额等）。
3. 创建 `tron_withdrawals` 记录，`status = created` 或 `reviewing`。
4. 审核通过后置为 `approved`，写入队列。
5. Withdrawal Worker：
   - 从队列取出待处理记录；
   - 使用热钱包私钥签名 TRC20 `transfer(to_address, amount)`；
   - 广播交易，记录 `txid`；
   - 标记状态为 `broadcasted`；
6. 定时任务查询交易确认情况：
   - 确认数达标则更新 `status = confirmed`。

**伪代码轮廓：**

```php
class TronWithdrawalService
{
    private string $hotWalletAddress = 'Txxxxx...';

    public function requestWithdrawal(int $userId, string $toAddress, float $amount)
    {
        // 1. 校验余额 & 风控
        // TODO: 调整为你内部实际的余额系统 & 风控逻辑

        // 2. 创建提币请求记录
        DB::table('tron_withdrawals')->insert([
            'user_id'   => $userId,
            'to_address'=> $toAddress,
            'amount'    => $amount,
            'fee'       => 0,   // 视情况填写
            'status'    => 'created',
        ]);
    }

    public function processApprovedWithdrawals()
    {
        // 取出 approved 状态的提币请求
        $rows = DB::table('tron_withdrawals')
            ->where('status', 'approved')
            ->get();

        foreach ($rows as $row) {
            // 调用热钱包进行转账
            $txid = $this->sendFromHotWallet($row->to_address, $row->amount);

            DB::table('tron_withdrawals')
                ->where('id', $row->id)
                ->update([
                    'txid'   => $txid,
                    'status' => 'broadcasted',
                ]);
        }
    }

    private function sendFromHotWallet(string $toAddress, float $amount): string
    {
        // TODO:
        // 1. 从安全存储获取热钱包私钥（不建议和用户地址私钥共用存储）
        // 2. 使用 TronUsdtContract 类进行 transfer 签名 & 广播
        return 'dummy-txid';
    }
}
```

---

## 3. 目录结构建议（方便 Cursor 拆分实现）

推荐在 `app/Services/Tron/` 下创建一套 Tron 专用服务类：

```text
app/
  Services/
    Tron/
      TronWalletService.php         # 生成地址、管理加密私钥
      TronNodeClient.php            # Tron 节点/TronGrid API 封装
      TronUsdtContract.php          # USDT-TRC20 合约封装（查询余额、transfer 等）
      TronDepositService.php        # 充值扫描 & 入账逻辑
      TronSweepService.php          # 归集逻辑
      TronWithdrawalService.php     # 提币逻辑

app/Console/Commands/
  TronScanDeposits.php              # 调用 TronDepositService::scanNewDeposits()
  TronUpdateDepositConfirms.php     # 调用 TronDepositService::updateConfirmationsAndCredit()
  TronSweepTask.php                 # 调用 TronSweepService::sweepAll()
  TronProcessWithdrawals.php        # 调用 TronWithdrawalService::processApprovedWithdrawals()
```

你可以直接让 Cursor 按这个结构生成骨架文件，并逐步补实现。

---

## 4. 钱包安全设计

你之前问的重点是：**“因为是一套私钥控制的，怎么保证安全？”**

这里分几层说明。

### 4.1 最低安全要求（当前阶段必须做到）

1. **私钥绝不明文落库**
   - 数据库中只存 `encrypted_private_key`。
   - 加密密钥通过环境变量 (`TRON_PK_ENC_KEY`) 或云端 KMS 管理。
   - 日志中禁止输出明文私钥、助记词。

2. **钱包逻辑与业务逻辑解耦**
   - 建议将生成地址、解密私钥、签名交易等逻辑放在单独的 “Wallet Service” 中：
     - 可以是同一代码库下的 module，也可以是专门的微服务。
   - Web/API 服务只做业务，不直接接触私钥明文。
   - Wallet Service 只开放内部 RPC（HTTP/gRPC），做严格鉴权和访问控制。

3. **热/冷钱包分层**
   - 热钱包只保留业务需要的流动性额度。
   - 大额资产迁移到冷钱包（硬件钱包、多签钱包等），通过人工流程划拨资金。

4. **权限与访问控制**
   - 访问 Wallet Service 的服务有白名单/IP 限制。
   - 后台运维人员权限分级，禁止所有人随意访问生产私钥。

---

### 4.2 进一步加强安全（下一阶段可以考虑）

1. **使用云 KMS 管理主密钥 / HD 种子**
   - 将 HD 主种子 / AES 密钥存放在云厂商 KMS（AWS KMS / GCP KMS 等）。
   - 应用只拿到 “使用 KMS 解密/签名” 的接口，真正的明文 key 不落盘。

2. **多主种子 / 分片策略**
   - 不把所有用户地址都挂在一个种子/一套私钥上。
   - 可以按业务线、批次、日期拆分多套种子，降低单点泄露的影响。

3. **MPC / HSM**
   - 上到 CEX 体量，一般会用 MPC（多方计算）或 HSM（硬件安全模块）来管理热钱包。
   - 这层成本较高，可以等业务发展到一定阶段再升级。

4. **风控与限额**
   - 用户提币限额：按日/按 KYC 等级区分。
   - 异常登录/设备/IP 时锁定提币。
   - 大额提币需人工审核、多签确认。

5. **监控与告警**
   - 钱包余额监控、归集/提币失败率监控。
   - Gas/能量消耗异常时告警。
   - 归集/提币 txid 的确认情况异常告警。

---

## 5. 关于 HD 钱包和子地址数量上限

你问到：**“HD 的子钱包有数量上限吗？”**

简要说明：

- 常见的 HD 钱包标准是 BIP32/BIP44。
- Tron 对应的 `coin_type` 一般使用 `195`，典型路径为：
  - `m / 44' / 195' / 0' / 0 / index`
- 其中 `index` 通常是一个 32-bit 非负整数（0,1,2,...），理论上可用到 `2^31 - 1`。

也就是说，从理论上讲，你可以从一个 HD 种子派生出**几十亿级别**子地址，远超过任何现实项目的需求。

但要注意：

- HD 主种子一旦泄露，理论上可以推导出所有子地址私钥。
- 生产上可采用多主种子、冷/热分层等方式减少单点风险。

对你目前的场景可以简单理解为：**HD 子地址数量不会成为瓶颈**，真正需要关注的是私钥/种子的安全托管和访问控制。

---

## 6. 给 Cursor 的使用提示

如果你把这个 README 丢给 Cursor，可以让它按以下步骤工作：

1. 先生成目录与空类：
   - `TronWalletService.php`
   - `TronNodeClient.php`
   - `TronUsdtContract.php`
   - `TronDepositService.php`
   - `TronSweepService.php`
   - `TronWithdrawalService.php`
2. 再依次实现：
   - 地址生成 & 私钥加解密逻辑；
   - Tron 节点/TronGrid API 请求封装；
   - USDT 合约调用（balanceOf/transfer）；
   - 充值扫描、确认与内部账本入账；
   - 归集逻辑（含 Gas Bank 账户打 TRX）；
   - 提币系统（含队列与状态机）。

这样你就可以在 PHP 项目里逐步落地这套 Tron TRC20 USDT 充值/提币系统。
