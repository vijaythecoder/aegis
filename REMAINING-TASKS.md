# Aegis — Remaining Tasks (Phase 4 continued)

> Tasks 1-31 are complete. These 4 tasks remain for full launch readiness.

---

## Task 32: iMessage Adapter (macOS-only)

**Status**: Not started
**Priority**: Medium (nice-to-have, macOS-only)

**What to do**:
- Create `app/Messaging/Adapters/IMessageAdapter.php`
- macOS-only: AppleScript to read/send iMessages via `osascript`
- Poll for new messages (no webhook available)
- Alternative: read `~/Library/Messages/chat.db` SQLite
- Background polling job: check every 5 seconds
- Guard: `PHP_OS === 'Darwin'` before enabling
- Warn in Settings: "Requires Full Disk Access, macOS only"

**Constraints**:
- macOS only (disabled on Windows/Linux)
- No group iMessage
- No iCloud messages
- No bypassing macOS security prompts

**Acceptance Criteria**:
- [ ] Detects macOS and enables adapter
- [ ] Gracefully disabled on Windows/Linux
- [ ] AppleScript sends test message (mocked in tests)
- [ ] New messages detected via polling
- [ ] `php artisan test --filter=IMessageAdapter` passes

---

## Task 33: Signal Adapter

**Status**: Not started
**Priority**: Medium (experimental)

**What to do**:
- Create `app/Messaging/Adapters/SignalAdapter.php`
- Use `signal-cli` (unofficial): JSON-RPC or dbus
- Receive: `signal-cli receive --json`
- Send: `signal-cli send -m "..." RECIPIENT`
- `aegis:signal:setup` artisan command for guided setup
- Mark as "Experimental" in Settings

**Constraints**:
- `signal-cli` installed separately (not bundled)
- Requires Java runtime
- Phone number required
- Plain text only (no rich formatting)
- No group support

**Acceptance Criteria**:
- [ ] signal-cli detection: present or "not installed"
- [ ] Messages sent/received via signal-cli (mocked)
- [ ] Setup guide walks through registration
- [ ] `php artisan test --filter=SignalAdapter` passes

---

## Task 34: NativePHP Air Mobile Companion

**Status**: Not started
**Priority**: Medium (stretch goal)

**What to do**:
- Create companion mobile app with NativePHP Air (v3)
- **Client mode**: connects to desktop Aegis via local network API
- Desktop exposes `http://local-ip:8001/api/mobile`
- QR code pairing (generates Sanctum auth token)
- Mobile-specific: push notifications, voice-to-text, camera, share sheet
- Responsive chat UI (Blade + Tailwind)

**Constraints**:
- No standalone mobile agent (client mode only)
- No mobile-specific tools
- No mobile settings (manage via desktop)
- No App Store/Play Store submission (APK/TestFlight)

**Acceptance Criteria**:
- [ ] Mobile app builds with NativePHP Air
- [ ] QR code pairing connects to desktop
- [ ] Chat round-trip: mobile -> desktop -> response -> mobile
- [ ] Push notification for approval requests
- [ ] Responsive UI on mobile viewport

---

## Task 35: Final Integration Testing, Security Audit & Launch Readiness

**Status**: Not started
**Priority**: High (launch gate)

**What to do**:
- Full system integration test across all features
- Security audit: PHPStan level 8, `composer audit`, secret scanning, OWASP Top 10
- Performance benchmarks: cold start <5s, first token <1s, memory <500MB idle
- Documentation: README.md, SECURITY.md, CONTRIBUTING.md, CHANGELOG.md
- Launch readiness checklist

**Acceptance Criteria**:
- [ ] `php artisan test --parallel` passes (300+ tests)
- [ ] PHPStan level 8: 0 errors
- [ ] `composer audit`: 0 vulnerabilities
- [ ] All installer builds complete
- [ ] Auto-update verified
- [ ] README, SECURITY, CONTRIBUTING docs exist
- [ ] No plaintext secrets in codebase

---

## Current Stats

| Metric | Value |
|--------|-------|
| Tests passing | 314 |
| Assertions | 921 |
| Test duration | ~8s |
| Tasks complete | 31/35 |
| Phases complete | Phase 1, 2, 3 (full), Phase 4 (partial) |
