# Security Policy

## Supported Versions

We support the latest version of the plugin. Please ensure you are using the most recent release.

| Version | Supported          |
| ------- | ------------------ |
| 2.3.x   | :white_check_mark: |
| < 2.3   | :x:                |

## Reporting a Vulnerability

If you discover a security vulnerability within this project, please send an e-mail to Petr Novák at **jsem@petrnovak.com**. All security vulnerabilities will be promptly addressed.

**Please do not open a public issue for security vulnerabilities.**

## Security Measures

This plugin implements the following security measures:
*   **Sanitization**: All inputs are sanitized before processing.
*   **Escaping**: All outputs are escaped to prevent XSS.
*   **Nonces**: All actions and AJAX requests are protected with nonces.
*   **Capabilities**: User capabilities are checked before allowing any administrative actions.
*   **API Keys**: API keys are stored securely in the database.
