# FollowPlus 项目 - Cursor Issue / 任务模板

此文件可直接导入到 Cursor 作为五大模块的 issue 说明，逐步生成与提交代码。

---

## 🧱 1. Foundation & Identity
**目标**：建立基础架构、身份认证、KYC 骨架、审计与配置。

**任务清单**
- [ ] 初始化 Laravel 项目结构（DDD 分层）
- [ ] 添加 Auth 模块（注册/登录/JWT/刷新）
- [ ] 中间件：Idempotency-Key、RateLimit、Auth
- [ ] 系统配置表 system_configs + 审计表 audit_logs
- [ ] 用户表/资料表/user_kyc 建模
- [ ] 管理员种子与基础交易对
- [ ] 单元测试：认证、幂等验证、审计记录

**验收标准**
✅ JWT 可用，配置热更，审计可查，KYC 状态流存在占位接口

---

## 💰 2. Accounts & Ledger
**目标**：建立资产账户体系、账本、入金提现、划转、闪兑占位。

**任务清单**
- [ ] currencies / accounts / ledger_entries / user_assets_summary
- [ ] deposits / withdrawals / internal_transfers / swaps
- [ ] 提现逻辑（新人扣除、老人手续费）
- [ ] 账本双向记账与余额守恒校验
- [ ] Feature Test: withdrawable计算正确性
- [ ] 审计提现/入金流

**验收标准**
✅ 并发记账安全，提现计算正确，审计与资产总额一致

---

## 🧩 3. Referral & Rewards Engine
**目标**：实现邀请关系、奖励逻辑、等级晋升与分红。

**任务清单**
- [ ] 用户 ref_path / ref_depth 设计
- [ ] ref_stats / ref_events / ref_rewards 表结构与服务
- [ ] 首充奖励 10% / 通知/上级 5% / 次日奖励 10%
- [ ] 等级升级规则 L1-L5
- [ ] 周期分红与断链逻辑
- [ ] 定时任务 T+1 奖励派发
- [ ] 单测覆盖：奖励计算、断链人数重算

**验收标准**
✅ 奖励金额正确流转，断链后团队人数正确，分红派发周期可控

---

## ⚙️ 4. Follow Core
**目标**：搭建完整跟单逻辑（窗口、邀请码、配额、下单、结算）。

**任务清单**
- [ ] follow_windows / invite_tokens / follow_orders / counters 建模
- [ ] 生成基础窗口（13/20点）与加餐窗口（12/14/19/21点）
- [ ] 跟单码验证 + 名额校验 + 下单金额=总资产1%
- [ ] 结算任务（利润=1%×[0.50~0.60]）
- [ ] 结算写账本 follow_settle 记入 profit_balance
- [ ] 单测覆盖：配额消费优先级、结算金额正确性

**验收标准**
✅ 名额逻辑准确，下单/结算全闭环，账本利润入账无误

---

## 📊 5. Market, System, Admin & Observability
**目标**：提供行情、系统页、后台管理面板与可观测性。

**任务清单**
- [ ] symbols / symbol_ticks 假数据刷新
- [ ] 系统公告 / 帮助 / 版本 / APP 下载接口
- [ ] 管理后台（Filament/Nova）：入金确认、提现审批、窗体发布
- [ ] 监控指标（队列、任务健康）
- [ ] Feature Test: 行情刷新与后台操作审计

**验收标准**
✅ 后台可操作，行情可用，监控告警可视化

---

## 🔄 顺序建议
1️⃣ Foundation → 2️⃣ Ledger → 3️⃣ Referral & 4️⃣ Follow 并行 → 5️⃣ Admin 收尾
