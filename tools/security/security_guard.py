#!/usr/bin/env python3
"""
ReSoK Security Guard

Defensive repository scanner for authentication, SQL injection, XSS,
rate limiting, HTTPS enforcement, headers, upload handling, environment
configuration, suspicious code patterns, and dependency audit hooks.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Iterable


ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CONFIG = Path(__file__).with_name("security_guard_config.json")


@dataclass
class Finding:
    severity: str
    category: str
    path: str
    line: int
    message: str
    recommendation: str


SEVERITY_ORDER = {"critical": 0, "high": 1, "medium": 2, "low": 3, "info": 4, "pass": 5}


PATTERNS = [
    (
        "high",
        "SQL injection",
        re.compile(r"\b(query|execute|prepare)\s*\([^;\n]*(?:\+|\$\{|\.\s*\$)", re.I),
        "Dynamic SQL construction detected.",
        "Use parameterized queries/placeholders and keep user input out of SQL strings.",
    ),
    (
        "high",
        "XSS",
        re.compile(r"\.innerHTML\s*=\s*(?!`<|\"<|'<)|insertAdjacentHTML\s*\(", re.I),
        "Potential unsafe HTML insertion.",
        "Use textContent for untrusted content or sanitize before inserting HTML.",
    ),
    (
        "high",
        "Command execution",
        re.compile(r"\b(exec|shell_exec|system|passthru|child_process\.exec|eval)\s*\(", re.I),
        "Dangerous execution sink found.",
        "Avoid command/eval execution; if unavoidable, use strict allowlists and argument arrays.",
    ),
    (
        "medium",
        "Secrets",
        re.compile(r"(?i)(password|secret|token|api[_-]?key)\s*[:=]\s*['\"][^'\"\n]{8,}['\"]"),
        "Possible hard-coded secret.",
        "Move secrets to environment variables or server-only config excluded from version control.",
    ),
    (
        "medium",
        "Debug",
        re.compile(r"\b(console\.log|var_dump|print_r|debug\s*=>\s*true)\b", re.I),
        "Debug output may leak sensitive runtime data.",
        "Disable debug output in production and log sensitive events server-side only.",
    ),
    (
        "medium",
        "Open redirect",
        re.compile(r"(window\.location|location\.href|res\.redirect)\s*=\s*[^;\n]*(next|return|url|redirect)", re.I),
        "Potential redirect using request-controlled data.",
        "Validate redirects against a local allowlist before redirecting.",
    ),
]


def load_config(path: Path) -> dict:
    if not path.exists():
        return {}
    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle)


def rel(path: Path) -> str:
    try:
        return str(path.relative_to(ROOT)).replace("\\", "/")
    except ValueError:
        return str(path).replace("\\", "/")


def iter_files(config: dict) -> Iterable[Path]:
    excluded = {Path(item).as_posix().strip("/") for item in config.get("exclude_dirs", [])}
    extensions = set(config.get("scan_extensions", []))
    max_size = int(config.get("max_file_size_bytes", 1024 * 1024))

    for path in ROOT.rglob("*"):
        if not path.is_file():
            continue
        relative = rel(path)
        parts = Path(relative).parts
        if any("/".join(parts[: idx + 1]) in excluded or part in excluded for idx, part in enumerate(parts)):
            continue
        if extensions and path.suffix.lower() not in extensions:
            continue
        try:
            if path.stat().st_size > max_size:
                continue
        except OSError:
            continue
        yield path


def add(findings: list[Finding], severity: str, category: str, path: Path | str, line: int, message: str, recommendation: str) -> None:
    findings.append(Finding(severity, category, rel(path) if isinstance(path, Path) else path, line, message, recommendation))


def scan_patterns(files: Iterable[Path]) -> list[Finding]:
    findings: list[Finding] = []
    for path in files:
        try:
            lines = path.read_text(encoding="utf-8", errors="ignore").splitlines()
        except OSError:
            continue
        for number, line in enumerate(lines, start=1):
            for severity, category, regex, message, recommendation in PATTERNS:
                if regex.search(line):
                    add(findings, severity, category, path, number, message, recommendation)
    return findings


def scan_auth() -> list[Finding]:
    findings: list[Finding] = []
    auth = ROOT / "resok-portal/server/controllers/authController.js"
    text = auth.read_text(encoding="utf-8", errors="ignore") if auth.exists() else ""
    if "bcrypt.hash(password, 12)" in text:
        add(findings, "pass", "Authentication", auth, 1, "Password hashing uses bcrypt with cost 12.", "Keep cost reviewed as server capacity changes.")
    else:
        add(findings, "high", "Authentication", auth, 1, "Could not confirm strong bcrypt password hashing.", "Hash passwords with bcrypt/argon2 and a modern work factor.")
    if "Invalid credentials" in text and "rows.length === 0" in text:
        add(findings, "pass", "Authentication", auth, 1, "Login avoids revealing whether an email exists.", "Keep generic login errors.")
    if "email_verified" in text:
        add(findings, "pass", "Authentication", auth, 1, "Email verification is enforced in login flow.", "Require verification in production.")
    if re.search(r"jwt\.sign\([^;]+expiresIn", text, re.S):
        add(findings, "pass", "Authentication", auth, 1, "JWT expiration is configured.", "Use short lifetimes for high-risk roles.")
    return findings


def scan_security_middleware() -> list[Finding]:
    findings: list[Finding] = []
    server = ROOT / "resok-portal/server/server.js"
    text = server.read_text(encoding="utf-8", errors="ignore") if server.exists() else ""
    checks = [
        ("helmet(", "pass", "Security headers", "Helmet middleware is enabled.", "Keep CSP tuned for production."),
        ("rateLimit(", "pass", "Rate limiting", "API rate limiting is enabled.", "Add stricter limits around login/password reset endpoints."),
        ("app.disable('x-powered-by')", "pass", "Security headers", "Express x-powered-by header is disabled.", "Keep implementation details hidden."),
        ("trust proxy", "pass", "HTTPS/proxy", "Trust proxy is configured for reverse proxy deployments.", "Set TRUST_PROXY correctly on production hosting."),
    ]
    for needle, severity, category, message, recommendation in checks:
        if needle in text:
            add(findings, severity, category, server, 1, message, recommendation)
    if "contentSecurityPolicy: false" in text:
        add(findings, "medium", "Security headers", server, 1, "Helmet CSP is currently disabled.", "Enable a Content-Security-Policy once inline scripts/styles are reduced or nonce-based.")
    if "req.secure" not in text and "Strict-Transport-Security" not in text:
        add(findings, "medium", "HTTPS enforcement", server, 1, "Could not confirm application-level HTTPS redirect/HSTS enforcement.", "Enforce HTTPS at the proxy/server and send HSTS in production.")

    php = ROOT / "resok-portal/public/api/index.php"
    php_text = php.read_text(encoding="utf-8", errors="ignore") if php.exists() else ""
    for header in ["X-Content-Type-Options", "X-Frame-Options", "Referrer-Policy"]:
        if header in php_text:
            add(findings, "pass", "Security headers", php, 1, f"PHP API sends {header}.", "Keep API security headers enabled.")
    if "Content-Security-Policy" not in php_text:
        add(findings, "low", "Security headers", php, 1, "PHP API does not send Content-Security-Policy.", "Add CSP for HTML responses; API JSON responses are lower risk.")
    return findings


def scan_uploads(config: dict) -> list[Finding]:
    findings: list[Finding] = []
    upload = ROOT / "resok-portal/server/middleware/upload.js"
    text = upload.read_text(encoding="utf-8", errors="ignore") if upload.exists() else ""
    for needle, message in [
        ("fileSize", "Upload size limit is configured."),
        ("allowedMime", "Upload MIME allowlist is configured."),
        ("allowedExt", "Upload extension allowlist is configured."),
        ("replace(/[^a-z0-9_-]/gi", "Upload subdirectory names are normalized."),
    ]:
        if needle in text:
            add(findings, "pass", "File upload protection", upload, 1, message, "Keep allowlists narrow and store uploads outside the public web root.")
    if "crypto.randomBytes" not in text:
        add(findings, "medium", "File upload protection", upload, 1, "Upload filenames use timestamp/random number, not cryptographic randomness.", "Use crypto.randomBytes for generated upload filenames.")
    if "finfo_open" in (ROOT / "resok-portal/public/api/index.php").read_text(encoding="utf-8", errors="ignore"):
        add(findings, "pass", "File upload protection", ROOT / "resok-portal/public/api/index.php", 1, "PHP upload handling verifies MIME with finfo.", "Keep MIME validation server-side.")
    return findings


def scan_env(config: dict) -> list[Finding]:
    findings: list[Finding] = []
    env_js = ROOT / "resok-portal/server/config/env.js"
    text = env_js.read_text(encoding="utf-8", errors="ignore") if env_js.exists() else ""
    for name in config.get("required_node_env", []):
        if f'required("{name}")' in text or f"process.env.{name}" in text:
            add(findings, "pass", "Environment variables", env_js, 1, f"{name} is referenced by server env validation.", "Set this in production environment only.")
        else:
            add(findings, "low", "Environment variables", env_js, 1, f"{name} was not found in server env validation.", "Add validation if this setting is required in production.")

    sample = ROOT / "resok-portal/public/api/config.sample.php"
    sample_text = sample.read_text(encoding="utf-8", errors="ignore") if sample.exists() else ""
    for key in config.get("required_php_config_keys", []):
        if f"'{key}'" in sample_text or f'"{key}"' in sample_text:
            add(findings, "pass", "Environment/config", sample, 1, f"PHP config documents {key}.", "Use production values outside version control.")
        else:
            add(findings, "low", "Environment/config", sample, 1, f"PHP config sample is missing {key}.", "Document required production config keys.")
    return findings


def scan_dependencies(timeout_seconds: int) -> list[Finding]:
    findings: list[Finding] = []
    package = ROOT / "resok-portal/package.json"
    if not package.exists():
        return findings
    if shutil.which("npm"):
        try:
            result = subprocess.run(
                ["npm", "audit", "--json", "--production"],
                cwd=str(package.parent),
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                timeout=timeout_seconds,
                check=False,
            )
            if result.stdout.strip():
                data = json.loads(result.stdout)
                total = data.get("metadata", {}).get("vulnerabilities", {}).get("total", 0)
                severity = "pass" if total == 0 else "high"
                add(findings, severity, "Dependency scanning", package, 1, f"npm audit reported {total} production vulnerabilities.", "Run npm audit fix only after reviewing breaking changes.")
            else:
                add(findings, "low", "Dependency scanning", package, 1, "npm audit did not return JSON output.", result.stderr.strip()[:180] or "Run npm audit manually.")
        except (subprocess.TimeoutExpired, json.JSONDecodeError, OSError) as exc:
            add(findings, "low", "Dependency scanning", package, 1, f"npm audit could not complete: {exc}", "Run npm audit manually in resok-portal.")
    else:
        add(findings, "info", "Dependency scanning", package, 1, "npm is not available, dependency audit skipped.", "Install Node/npm and run npm audit before deployment.")
    return findings


def write_report(findings: list[Finding], path: Path) -> None:
    counts: dict[str, int] = {}
    for finding in findings:
        counts[finding.severity] = counts.get(finding.severity, 0) + 1

    lines = [
        "# ReSoK Security Guard Report",
        "",
        "## Summary",
        "",
    ]
    for severity in ["critical", "high", "medium", "low", "info", "pass"]:
        if counts.get(severity):
            lines.append(f"- {severity}: {counts[severity]}")
    lines.extend(["", "## Findings", ""])
    for finding in findings:
        location = f"{finding.path}:{finding.line}" if finding.line else finding.path
        lines.extend(
            [
                f"### [{finding.severity.upper()}] {finding.category}",
                f"- Location: `{location}`",
                f"- Issue: {finding.message}",
                f"- Recommendation: {finding.recommendation}",
                "",
            ]
        )
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("\n".join(lines), encoding="utf-8")


def print_summary(findings: list[Finding]) -> None:
    actionable = [item for item in findings if item.severity in {"critical", "high", "medium"}]
    print("Security Guard finished.")
    print(f"Findings: {len(findings)} total, {len(actionable)} actionable high/medium/critical.")
    for item in actionable[:12]:
        print(f"- {item.severity.upper()} {item.category} {item.path}:{item.line} - {item.message}")
    if len(actionable) > 12:
        print(f"- ... {len(actionable) - 12} more actionable findings in the report.")


def main() -> int:
    parser = argparse.ArgumentParser(description="Run ReSoK defensive security checks.")
    parser.add_argument("--config", type=Path, default=DEFAULT_CONFIG)
    parser.add_argument("--report", type=Path, default=ROOT / "security-report.md")
    parser.add_argument("--json", type=Path, default=None, help="Optional JSON output path.")
    parser.add_argument("--fail-on", choices=["critical", "high", "medium", "low"], default="high")
    parser.add_argument("--skip-deps", action="store_true", help="Skip npm audit dependency scanning.")
    parser.add_argument("--dependency-timeout", type=int, default=20, help="Seconds to wait for npm audit.")
    args = parser.parse_args()

    config = load_config(args.config)
    files = list(iter_files(config))
    findings: list[Finding] = []
    findings.extend(scan_patterns(files))
    findings.extend(scan_auth())
    findings.extend(scan_security_middleware())
    findings.extend(scan_uploads(config))
    findings.extend(scan_env(config))
    if args.skip_deps:
        add(findings, "info", "Dependency scanning", "resok-portal/package.json", 1, "Dependency audit skipped by --skip-deps.", "Run npm audit before deployment when network access is available.")
    else:
        findings.extend(scan_dependencies(args.dependency_timeout))
    findings.sort(key=lambda item: (SEVERITY_ORDER.get(item.severity, 99), item.category, item.path, item.line))

    write_report(findings, args.report)
    if args.json:
        args.json.parent.mkdir(parents=True, exist_ok=True)
        args.json.write_text(json.dumps([asdict(item) for item in findings], indent=2), encoding="utf-8")
    print_summary(findings)
    print(f"Markdown report: {rel(args.report.resolve())}")
    if args.json:
        print(f"JSON report: {rel(args.json.resolve())}")

    threshold = SEVERITY_ORDER[args.fail_on]
    has_failure = any(SEVERITY_ORDER.get(item.severity, 99) <= threshold for item in findings)
    return 1 if has_failure else 0


if __name__ == "__main__":
    sys.exit(main())
