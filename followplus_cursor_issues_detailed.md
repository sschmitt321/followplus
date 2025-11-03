# FollowPlus – Cursor 执行用「五大模块详细任务手册」（可直接驱动代码生成）

> 用法：把本文件放在仓库根目录（或贴给 Cursor），逐任务执行。每一条任务都包含 **范围→产出→详细步骤→接口契约→迁移清单→服务方法→测试用例→验收标准→提交信息模版**，可直接让 Cursor 生成代码。

---

## 模块 1）Foundation & Identity（基础平台与身份认证）

### 范围
- 脚手架与分层、JWT 登录注册、Argon2id 密码、幂等与限流、系统配置、审计日志、KYC 骨架。

### 产出清单
- 目录结构落地（见前置 cursorrules）
- 中间件：`Idempotency`, `Auth(JWT)`, `RateLimiter`
- 表：`users`, `user_profiles`, `user_kyc`, `system_configs`, `audit_logs`
- `AuthController`, `KycController`, `SystemConfigController`
- 基础种子：管理员、基础 symbols
- OpenAPI `/docs`

### 迁移清单（DDL 概述）
1. `users`
   - `email(uniq)`, `password_hash`, `invite_code(uniq)`, `invited_by_user_id(NULL)`, `ref_path`, `ref_depth`, `role`, `status`, `first_joined_at`
   - 索引：`idx_invited_by`, `idx_ref_path(255)`
2. `user_profiles`：`user_id(uniq)`, `name`, `city`
3. `user_kyc`：`user_id(uniq)`, `level(enum none/basic/advanced)`, `status(enum pending/approved/rejected)`, `front_image_url`, `back_image_url`, `review_reason`
4. `system_configs`：`key(uniq)`, `val`, `version`, `updated_by`
5. `audit_logs`：`id`, `user_id?`, `action`, `resource`, `before_json`, `after_json`, `ip`, `ua`

> DDL 要求：金额不用到本模块；所有表含 `created_at/updated_at/deleted_at?`。

### 控制器/接口（契约）
- `POST /api/v1/auth/register {email,password,invite_code?}` → 200 `{access,refresh}`
- `POST /api/v1/auth/login {email,password}` → 200 `{access,refresh}`
- `POST /api/v1/auth/refresh {refresh}` → 200 `{access}`
- `GET /api/v1/me` → 200 `{user, profile, kyc, role}`
- `GET /api/v1/kyc/status`、`POST /api/v1/kyc/basic {name}`、`POST /api/v1/kyc/advanced {front,back}`（占位）
- `GET /api/v1/system/configs`（只读）

### 服务方法
- `AuthService::register`, `login`, `refresh`
- `KycService::submitBasic`, `submitAdvanced`, `review`
- `Audit::log(userId, action, resource, before, after)`
- `ConfigService::get(key)`, `set(key,val,operator)`

### 中间件
- `IdempotencyMiddleware`：识别 `Idempotency-Key`，Redis 24h 去重；对记账/提现/下单类接口强制
- `JwtAuthMiddleware`：验证与续期
- `RateLimitMiddleware`：IP+User 维度节流

### 测试用例（Pest/Feature+Unit）
- 注册→登录→刷新流畅；重复注册报错
- RateLimit 命中与放行
- Idempotency：同 key 多次请求只产生一次副作用
- 审计写入与读取

### 验收标准
- OpenAPI 生效；JWT 与中间件可靠；KYC 占位可用；管理员种子存在

### 提交信息模版
- `feat(auth): add jwt register/login/refresh with argon2id`
- `feat(core): add idempotency and audit logs`
- `test(auth): add feature tests for jwt flow`

---

## 模块 2）Accounts & Ledger（资产账户与账本）

### 范围
- 币种、账户（spot/contract）、账本分录、资产汇总；入金/提现/划转/闪兑占位；新人/老人提现策略。

### 产出清单
- 表：`currencies`, `accounts`, `ledger_entries`, `user_assets_summary`, `deposits`, `withdrawals`, `internal_transfers`, `swaps`
- 服务：`LedgerService`, `AssetsService`, `DepositService`, `WithdrawService`, `TransferService`, `SwapService(占位)`
- 控制器：`WalletsController`, `DepositController`, `WithdrawController`, `TransferController`, `SwapController`
- 可复用 Decimal 支持工具与 Money cast

### 迁移清单（DDL 概述）
- `currencies(name, precision, enabled)`
- `accounts(user_id, type enum[spot,contract], currency, available, frozen)` uniq(`user_id`,`type`,`currency`)
- `ledger_entries(user_id, account_id, currency, amount DECIMAL(36,6), balance_after, biz_type, ref_id, meta_json, created_at)`
- `user_assets_summary(user_id uniq, total_balance, principal_balance, profit_balance, bonus_balance)`
- `deposits(user_id, currency, chain, address, amount, status, txid, confirmed_at)`
- `withdrawals(user_id, currency, amount_request, fee, amount_actual, status, to_address, chain, txid)`
- `internal_transfers(user_id, currency, from_type, to_type, amount, status)`
- `swaps(user_id, from_currency, to_currency, rate_snapshot, amount_from, amount_to, status)`

### 接口（契约）
- `GET /api/v1/wallets`（展示充币地址与说明）
- `GET /api/v1/deposits`、`POST /api/v1/deposits/manual-apply {amount}`
- `GET /api/v1/withdrawals`、`POST /api/v1/withdrawals/apply {amount,to_address,withdraw_password}`
- `POST /api/v1/transfer {from:spot|contract,to:spot|contract,amount}`
- `POST /api/v1/swap/quote {from:USDT,to:BTC|ETH|USDC,amount}`、`POST /api/v1/swap/confirm {quote_id}`

### 关键规则
- 所有金额以 Decimal 字符串传输；服务层统一做舍入与精度
- **账本守恒**：每笔钱的增加/减少有对冲分录；`balance_after` 回写
- 提现：
  - 新人：仅保留“每日 2 次固定跟单”的奖励，扣除所有彩金/加餐/课程奖励，之后再扣 10% 手续费
  - 老人：按 `WITHDRAW_FEE_RATE_OLD`（默认 10%）
- 新人提现会没收邀请者的彩金（冲正 `ref_rewards`，此处先留接口钩子给模块 3 实现）

### 服务方法关键签名
- `LedgerService::credit/debit(userId, accountType, currency, amount, bizType, refId)`
- `AssetsService::getTotalBalance(userId): Decimal`
- `WithdrawService::calcWithdrawable(userId): {withdrawable, fee, policy}`
- `WithdrawService::apply(userId, amount, toAddress, payPwd)`

### 测试用例
- 并发记账 1000 次后余额正确
- 新人/老人提现公式边界（0、整额、小数）
- 账本与 `user_assets_summary` 一致
- 划转与闪兑占位能生成记录且不破坏余额

### 验收
- `/me` 资产汇总准确；提现/入金流程通；审计有迹可循

### 提交信息模版
- `feat(ledger): add double-entry ledger and accounts`
- `feat(withdraw): implement newbie vs old user policy`
- `test(ledger): add concurrency safe tests`

---

## 模块 3）Referral & Rewards Engine（邀请关系、等级与奖励）

### 范围
- 无层级上限的邀请树（ref_path）、三层有效奖励、等级（L1~L5）一次性奖励与分红、T+1 次日奖励、断链（下线提现后解绑并减少子树人数）。

### 产出清单
- 表：`ref_stats`, `ref_events`, `ref_rewards`
- 服务：`ReferralService`, `RewardService`, `TeamRecalcService`
- 定时任务：T+1 次日 10% 发放；周期分红派发
- 管理端 API：手工重算、奖励冲正

### 迁移清单（DDL 概述）
- `ref_stats(user_id PK, direct_count, team_count, ambassador_level, ambassador_reward_total, dividend_rate)`
- `ref_events(id, trigger_user_id, event_type, amount, meta_json)`
- `ref_rewards(id, user_id, source_user_id, type enum(...), amount, status enum(pending/confirmed/cancelled), ref_event_id)`

### 事件与规则
- 首充 10% → 推荐人；`notifier_5pct` 或 `upline_5pct`（缺通知人时）
- 被推荐人 T+1 次日 10%
- 等级：L1~L5（见 cursorrules 表格），一次性奖励 & 分红比例写入 `ref_stats.dividend_rate`
- 断链：当**直接下线**提现 `paid`，移除其上级，重写其及子树 `ref_path` 到根；邀请者 `team_count -= subtree_size`；触发等级/分红重算

### 接口（契约）
- `GET /api/v1/ref/summary` → `{direct_count, team_count, level, dividend_rate, total_rewards}`
- `GET /api/v1/ref/rewards?type=&status=` → 列表
- （Admin）`POST /api/v1/admin/ref/level-recalc {user_id?}`、`POST /api/v1/admin/ref/reward-reverse {reward_id}`

### 服务方法（关键签名）
- `ReferralService::bindInviter(userId, inviterCode)`
- `ReferralService::recalcTeamStats(rootUserId)`
- `RewardService::grantReferralOnDeposit(triggerUserId, amount, notifierUserId?)`
- `RewardService::grantNewbieNextDay(triggerUserId)`
- `RewardService::grantAmbassadorOneOff(userId, level)`
- `RewardService::dispatchDividend(cycleDate)`
- `ReferralService::onDirectDownlineWithdrawPaid(directChildId)`

### 测试
- 首充触发三段奖励正确 & 并发场景不重复发放（幂等）
- T+1 任务按期执行
- 断链后团队人数 = 原值 - 子树大小；`ref_path` 正确重写
- 等级晋升/降级与分红派发数额正确

### 验收
- 奖励流水与账本一致；断链后统计正确；可手工重算/冲正

### 提交信息模版
- `feat(ref): add referral tree with ref_path`
- `feat(reward): implement 10%/5% and T+1 rewards`
- `feat(ref): implement subtree detach on withdraw paid`
- `test(ref): cover rewards and detach cases`

---

## 模块 4）Follow Core（跟单：窗口/邀请码/配额/下单/结算）

### 范围
- 固定窗（13/20）与加餐窗（12/14/19/21），全员同码邀请码限时生效，配额（基础2次+加餐4次），下单金额恒等于总资产 1%，结算利润=1%×[0.50,0.60]。

### 产出清单
- 表：`follow_windows`, `invite_tokens`, `follow_orders`, `follow_counters`, `follow_bonus_windows`
- 服务：`FollowService`, `FollowQuotaService`
- 任务：每日 00:05 生成当天窗体与加餐窗；到期批量结算
- 控制器：`FollowController`

### 迁移清单（DDL 概述）
- `follow_windows(symbol_id, window_type enum(fixed_daily,newbie_bonus,inviter_bonus), start_at, expire_at, reward_rate_min,max, status)`
- `invite_tokens(follow_window_id, token, valid_after, valid_before, symbol_id)` uniq(`token`,`follow_window_id`)
- `follow_orders(user_id, follow_window_id, symbol_id, amount_base, amount_input, status enum(placed,expired,settled), profit)`
- `follow_counters(user_id, date, base_quota_used, extra_quota_used)` uniq(`user_id`,`date`)
- `follow_bonus_windows(user_id, reason enum(newbie_days2to6,inviter_ratio30pct), start_date, end_date, daily_extra_quota)`

### 接口（契约）
- `GET /api/v1/follow/windows/available?date=YYYY-MM-DD`
- `POST /api/v1/follow/order {follow_window_id, symbol_id, invite_token, amount_input?}`
- `GET /api/v1/follow/orders?status=`
- `GET /api/v1/follow/summary`（总金额/次数/盈利/胜率）

### 关键规则
- 校验：时间窗有效 + 跟单码有效 + 交易对一致 + 配额足够
- 财务：`amount_base = 当前总资产 × 0.01`（后端计算，前端输入仅审计）
- 结算：到期批量，`profit = amount_base × uniform(reward_rate_min,max)`

### 测试
- 配额优先级（先基础, 再加餐）
- 多窗多码场景下的正确性
- 结算金额与账本入账正确
- 过期窗无法下单

### 验收
- 常规/加餐全链路跑通；历史/汇总数据正确

### 提交信息模版
- `feat(follow): add windows/tokens and order flow`
- `feat(follow): implement settlement batch`
- `test(follow): add quota priority and settlement tests`

---

## 模块 5）Market, System, Admin & Observability（行情/系统/后台/可观测）

### 范围
- 行情伪数据、系统静态页接口、后台（入金确认、提现审核、窗口与邀请码发布、公告）、监控与队列健康。

### 产出清单
- 表：`symbols`, `symbol_ticks`
- 控制器：`MarketController`, `SystemController`, `Admin/*`
- 管理后台：Filament/Nova（任选其一）
- 指标：队列滞留、任务失败、关键接口错误率

### 迁移清单（DDL 概述）
- `symbols(base, quote, enabled)`
- `symbol_ticks(symbol_id, last_price, change_percent, tick_at)`

### 接口（契约）
- `GET /api/v1/symbols`、`GET /api/v1/symbols/{id}/tick`
- Admin：`POST /admin/follow-window`、`POST /admin/invite-token`、`POST /admin/deposit/confirm`、`POST /admin/withdraw/{id}/approve|reject|paid`、`POST /admin/system/announcement`

### 测试
- 行情刷新任务正确写入 tick
- 后台操作均有审计日志
- 权限隔离（仅 admin）

### 验收
- 后台可进行关键运营操作；监控有看板/告警

### 提交信息模版
- `feat(market): add symbols and ticks fake feed`
- `feat(admin): minimal panel with audits`
- `chore(observe): add queue/schedule metrics`

---

# 附录 A：Cursor 单任务提示模板（可直接贴给 Cursor）

> 模板参数：`<module>`, `<task>`，把下方块复制给 Cursor 即可。

### 1. 生成迁移 + 模型 + 仓储 + 服务骨架
```
实现 <module>/<task>：
1) 生成迁移、Eloquent 模型（含 casts/relations）、Repository 接口+实现、Service 类；
2) 遵循文件夹结构 app/Domain/*, app/Services/*；
3) 为所有 Decimal 金额字段统一使用 DECIMAL(36,6) 并加 casts；
4) 生成对应 Factory 与 Seeder（如需要）。
产出：修改列表 + 代码。测试：最少 1 个 Unit。
```

### 2. 实现 REST 控制器 + Request 校验 + 资源 Resource
```
为 <module>/<task> 实现 Controller/Request/Resource：
- 路由前缀 /api/v1，按 OpenAPI 契约返回 JSON；
- 所有写入接口强制 Idempotency-Key；
- 返回使用 Resource 封装，隐藏内部字段；
- 更新 OpenAPI 注释。
产出：路由/控制器/请求/资源。测试：1 个 Feature。
```

### 3. 队列与计划任务
```
为 <module>/<task> 实现 Job/Command：
- Job 放入 redis 队列，失败重试 3 次；
- Console Kernel 中注册 schedule；
- 加日志与审计；
- 提供 .env 示例开关。
测试：伪造时间/数据断言业务效果。
```

### 4. 并发与幂等测试
```
为 <module>/<task> 编写并发测试：
- 使用并行请求/事务模拟重复提交；
- 验证余额/奖励/订单无重复产生；
- 验证 Idempotency-Key 生效。
```

---

# 附录 B：提交与验收总清单（每 PR 必带）
- [ ] 迁移 + 回滚脚本可用
- [ ] OpenAPI 更新
- [ ] Unit + Feature Tests 通过（>=80% 覆盖关键路径）
- [ ] 队列/计划任务可运行（本地与 stage）
- [ ] 审计覆盖关键操作
- [ ] README 增量说明（如何启用/调参/回滚）

---

> END
