### AGENTS.md 项目说明

| 项目                        | 路径                                             | 职责                    |
| --------------------------- | ------------------------------------------------ | ----------------------- |
| V2Board                     | `/`                                              | PHP 后端、业务逻辑、API |
| V2Board Admin               | `frontend/admin/v2board-admin`                   | 管理端前端              |
| Kxuw                        | `frontend/theme/Kxuw`                            | 用户主题端前端          |
| V2Node                      | `services/v2node`                                | 节点服务端              |
| GeneralEncryptionMiddleware | `services/api-proxy/GeneralEncryptionMiddleware` | API 加密代理服务        |

开发规则：

- 主题端涉及 API 加密时，需要同步检查 GeneralEncryptionMiddleware。
- 修改 AES 加密协议时，必须检查前端与代理服务端的兼容性。
- 代理服务的密钥配置必须与主题端保持一致。
- 涉及请求体加密时，检查 `VITE_API_CONTENT_ENCRYPTION_ENABLED` 和 `CONTENT_ENCRYPTION_ENABLED`。
- 不要将代理服务的加解密逻辑直接复制到 V2Board PHP 后端。

特别提醒：该项目的请求体加密是在代理服务解密后再转发给 V2Board，因此它保护的是主题端到代理服务之间的请求体传输，并不代表整个链路都是端到端加密，生产环境仍然需要 HTTPS。