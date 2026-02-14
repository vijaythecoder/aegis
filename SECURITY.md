# Security Policy

## Reporting Vulnerabilities

Report security vulnerabilities via email to **security@aegis.dev** (or open a private security advisory on GitHub).

Do NOT open public issues for security vulnerabilities.

## Response Timeline

- Acknowledgment: within 48 hours
- Initial assessment: within 7 days
- Fix release: within 30 days for critical issues

## Security Architecture

### Encryption
- API keys encrypted at rest via Laravel's `Crypt::encryptString()` (AES-256-CBC)
- SQLite database with WAL mode for data integrity
- Ed25519 signatures for plugin verification

### Permission System
- Tool execution requires explicit user approval
- Path traversal protection on all file operations
- Shell injection prevention with blocked command patterns
- Audit logging of all tool executions

### Plugin Sandboxing
- Plugins run in isolated PHP processes
- Configurable memory and time limits
- Filesystem access restricted to plugin directory
- Network access controlled by permission grants

### Messaging Security
- Webhook signature verification on all platforms (HMAC-SHA256)
- Rate limiting on all messaging adapters
- Session isolation prevents cross-platform hijacking

### Dependencies
- Regular `composer audit` for vulnerability scanning
- Minimal dependency footprint
- No Docker dependency for sandboxing

## Supported Versions

| Version | Supported |
|---------|-----------|
| 0.1.x   | Yes       |
