#!/usr/bin/env python3
"""W6: rename the plugin's code names from def-core to Widrow AI (10.0.0).

Run from anywhere inside the repo:  python tools/w6-rename.py

What it does, in order:
  1. Checks every `def_core_*` token in the tree is classified (KEEP, RENAME, or
     a stored name listed in the alias table, includes/class-widrow-ai-legacy-names.php).
     An unclassified token stops the run before anything is written, so a key
     added on a newer base is never renamed without the migration moving it.
  2. Moves files (`git mv`) whose name carries `def-core` / `digital-employees`.
  3. Rewrites file contents: masks the KEEP strings, applies RULES and
     REGEX_RULES in order, restores the masks.
  4. Prints counts.

Idempotent: a second run on the renamed tree moves nothing and changes nothing.
It needs the alias table, so it runs on this branch, not on a bare main.

KEEP is the wire contract (REST namespaces, strings DEF and DEFHO send or read,
the documented `def_staff_access_required` error code), the block name saved in
post content and the encryption key-derivation constants. Stored names (option
keys, capabilities, transients, cron hooks, the log table, post and user meta)
ARE renamed here; the one-time migration in the alias table moves each site's
data to the new names on activation or upgrade, and the alias table (skipped
here) is the only file that keeps the old names.

Stdlib only. History files (changelog.txt, readme.txt) are not rewritten.
"""

import re
import subprocess
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(
    subprocess.check_output(
        ["git", "rev-parse", "--show-toplevel"], cwd=Path(__file__).resolve().parent, text=True
    ).strip()
)

# Not rewritten: third-party code, history, this script.
SKIP_PREFIXES = ("vendor/",)
ALIAS_TABLE = "includes/class-widrow-ai-legacy-names.php"
# The alias table and its migration test hold the old names on purpose.
SKIP_FILES = {"changelog.txt", "readme.txt", "tools/w6-rename.py", ALIAS_TABLE, "tests/test-legacy-names.php"}
BINARY_SUFFIXES = {".png", ".jpg", ".jpeg", ".gif", ".ico", ".mo", ".zip", ".woff", ".woff2", ".ttf"}

# ── Ordered substitution rules (applied to file contents and file names) ────
RULES = [
    ("a3rev-ai/def-core", "a3rev-ai/widrow"),  # the repo's own name (renamed in W2)
    ("def-core.php", "widrow-ai.php"),  # the main file
    ("DEF_Core", "Widrow_AI"),  # classes
    ("DEF_CORE", "WIDROW_AI"),  # constants
    ("DEFCore", "WidrowAI"),  # JS global (localized config)
    ("DefCore", "WidrowAi"),  # PHP namespace A3Rev\DefCore, JS globals
    ("defCore", "widrowAi"),  # JS globals
    ("def_core", "widrow_ai"),  # functions, hooks, nonces, AJAX actions, vars
    ("def-core", "widrow-ai"),  # slug, handles, CSS classes/ids, paths, prose
    ("digital-employees", "widrow-ai"),  # text domain, old zip/folder name
]

# ── def_core_* tokens that KEEP their value (masked as whole tokens) ────────
KEEP_TOKENS = {
    # Encryption: HKDF info string — changing it makes every stored secret unreadable.
    "def_core_secret_encryption",
}

# ── def_core_* tokens that are code names (renamed by RULES) ────────────────
RENAME_TOKENS = {
    "def_core_active_tab",  # localStorage key (browser only)
    "def_core_bulk_action", "def_core_bulk_count",  # query args
    "def_core_chat_button", "def_core_inited",  # actions
    "def_core_chat_strings", "def_core_customer_chat_handoff_body", "def_core_detected_language",
    "def_core_log_max_entries", "def_core_log_retention_days", "def_core_page_context",
    "def_core_page_context_allowed_attribute_taxonomies", "def_core_register_tools",
    "def_core_token_expiration", "def_core_visitor_ip",  # filters
    "def_core_clear_logs", "def_core_download_logs_csv", "def_core_inline_login",
    "def_core_oauth_disconnect", "def_core_oauth_start", "def_core_remove_user_roles",
    "def_core_save_manual_connection", "def_core_save_settings", "def_core_save_user_roles",
    "def_core_search_users", "def_core_test_connection", "def_core_test_escalation_email",  # AJAX actions / nonces
    "def_core_exclusion_nonce", "def_core_logs_action", "def_core_save_exclusion",  # nonces
    "def_core_exclude", "def_core_include", "def_core_exclusion",  # bulk actions, list column
    "def_core_knowledge_exclusion",  # meta box id
    "def_core_manual_api_key",  # DOM id
    "def_core_settings",  # settings group
    "def_core_pre_chat_trail", "def_core_pre_chat_trail_shipped_threads",  # sessionStorage keys
    "def_core_update_checker", "def_core_vcs_api",  # PHP globals
    "def_core_collect_page_terms",  # function named in a comment
    "def_core_push_roster",  # a test's stub marker
    # Prefixes of stored keys (escalation per channel, the chat escalation copy,
    # the Setup Assistant's short-key strip); the alias table lists the full keys.
    "def_core_", "def_core_escalation_", "def_core_chat_escalation_",
}

# ── Other strings that KEEP their value (regex, reason) ─────────────────────
KEEP_PATTERNS = [
    (r"def-core/v1", "wire: REST namespace /wp-json/def-core/v1 and its routes"),
    (r"def-core/chat-button", "stored: block name saved in post content"),
    (r"def-core-encryption-v1", "encryption: HKDF salt"),
    (r"'source' => 'def-core'", "wire: sync-nudge body sent to DEF"),
    (r"'def-core/'(?= \.)", "wire: User-Agent on the JWKS fetch"),
    (r"def_staff_access_required", "wire: the 403 error code integrations match on (documented)"),
    (r"widrow\.ai/digital-employees/", "public website URL"),
    (r"(?<=if \( defined\( ')DEF_CORE_PLUGIN_NAME(?=' \) \) \{\s*deactivate_plugins)|(?<=deactivate_plugins\( )DEF_CORE_PLUGIN_NAME",
     "the 9.x plugin's basename constant, read on activation to switch it off"),
]
# Old names written by hand on purpose, after the rename.
KEEP_PATTERNS_PER_FILE = {
    "tests/test-admin-api.php": [(r"(?s)// ── 26a\. .*?(?=// ── 27\. )", "test: the old names DEF sends (alias), section 26a")],
}

# ── Stored names outside def_core_* (regex, replacement), after RULES ───────
REGEX_RULES = [
    (r"(?<![A-Za-z0-9_])def_(admin|staff|management|ap)_access", r"widrow_\1_access"),  # capabilities
    (r"(?<![A-Za-z0-9_])def_role_", "widrow_role_"),  # custom-role capabilities, same slug
    (r"(?<![A-Za-z0-9_])DEF_CAPABILITIES(?![A-Za-z0-9_])", "WIDROW_CAPABILITIES"),
    (r"(?<![A-Za-z0-9_])def_capabilities(?![A-Za-z0-9_])", "widrow_capabilities"),
    (r"(?<![A-Za-z0-9_])def_service_auth_secret", "widrow_service_auth_secret"),
    (r"(?<![A-Za-z0-9_])def_(sa|test_email|gh_update)_", r"widrow_\1_"),  # user meta, transients, localStorage
    (r"(?<![A-Za-z0-9])_def_", "_widrow_"),  # post/order meta, PKCE options, session key, test globals
]
# A REGEX_RULES match must be a stored name in the alias table, or listed here
# as a name with nothing stored to move (an entry ending in "_" is a prefix).
REGEX_NOT_STORED = {
    "DEF_CAPABILITIES": "PHP constant",
    "def_capabilities": "PHP method and local variable",
    "def_sa_": "the drawer's localStorage key prefix (browser only)",
    "_def_test_": "test globals",
    "_def_scripts": "test global",
    "_def_styles": "test global",
    "_def_localized": "test global",
    "_def_caps": "test global",
    "_def_reset_recorders": "test helper",
}
REGEX_TOKEN_RES = [re.compile(pattern + r"[A-Za-z0-9_]*") for pattern, _ in REGEX_RULES]


def regex_tokens(text):
    return {m.group(0) for token_re in REGEX_TOKEN_RES for m in token_re.finditer(text)}


def classified(token, names):
    return token in names or any(n.endswith("_") and token.startswith(n) for n in names)

TOKEN_RE = re.compile(r"(?<![A-Za-z0-9])def_core_[A-Za-z0-9_]*")
KEEP_TOKEN_RE = re.compile(
    r"(?<![A-Za-z0-9])(?:" + "|".join(sorted(map(re.escape, KEEP_TOKENS), key=len, reverse=True)) + r")(?![A-Za-z0-9_])"
)


def git(*args):
    return subprocess.check_output(["git", *args], cwd=ROOT, text=True)


def tracked_files():
    return [p for p in git("ls-files", "-z").split("\0") if p]


def in_scope(path):
    return not path.startswith(SKIP_PREFIXES) and path not in SKIP_FILES and Path(path).suffix not in BINARY_SUFFIXES


def read_text(path):
    try:
        with open(ROOT / path, encoding="utf-8", newline="") as fh:
            return fh.read()
    except UnicodeDecodeError:
        return None


def apply_rules(text, counts, regex=False):
    for old, new in RULES:
        n = text.count(old)
        if n:
            counts[f"{old} -> {new}"] += n
            text = text.replace(old, new)
    for pattern, new in REGEX_RULES if regex else []:
        text, n = re.subn(pattern, new, text)
        if n:
            counts[f"{pattern} -> {new}"] += n
    return text


def rename_text(path, text, rule_counts, keep_counts):
    saved = []

    def mask(reason):
        def _sub(m):
            saved.append(m.group(0))
            keep_counts[reason] += 1
            return f"\x00{len(saved) - 1}\x00"
        return _sub

    text = KEEP_TOKEN_RE.sub(mask("stored/encryption: def_core_* key (KEEP_TOKENS)"), text)
    for pattern, reason in KEEP_PATTERNS + KEEP_PATTERNS_PER_FILE.get(path, []):
        text = re.sub(pattern, mask(reason), text)
    text = apply_rules(text, rule_counts, regex=True)
    # A mask can hold an earlier one (a per-file region around a REST path),
    # so restore until none is left.
    while "\x00" in text:
        text = re.sub(r"\x00(\d+)\x00", lambda m: saved[int(m.group(1))], text)
    return text


def main():
    files = [p for p in tracked_files() if in_scope(p)]

    # 1. Every def_core_* token, and every name a REGEX_RULES pattern would
    #    rewrite, must be classified before anything is written.
    alias_text = read_text(ALIAS_TABLE) or ""
    stored = set(TOKEN_RE.findall(alias_text))
    if not stored:
        print(f"STOP: {ALIAS_TABLE} is missing — the stored names are classified by it.")
        return 1
    regex_names = regex_tokens(alias_text) | set(REGEX_NOT_STORED)
    unclassified = Counter()
    for path in files:
        text = read_text(path)
        if text is None:
            continue
        for tok in TOKEN_RE.findall(text):
            if tok not in KEEP_TOKENS and tok not in RENAME_TOKENS and tok not in stored:
                unclassified[f"{tok}  ({path})"] += 1
        unmasked = text
        for pattern, _ in KEEP_PATTERNS + KEEP_PATTERNS_PER_FILE.get(path, []):
            unmasked = re.sub(pattern, "", unmasked)
        for tok in regex_tokens(unmasked):
            if not classified(tok, regex_names):
                unclassified[f"{tok}  ({path})"] += 1
    if unclassified:
        print("STOP: unclassified tokens — add each to KEEP_TOKENS, RENAME_TOKENS, REGEX_NOT_STORED or the alias table:")
        for item in sorted(unclassified):
            print("  " + item)
        return 1

    # 2. File moves.
    moves = []
    for path in tracked_files():
        if path.startswith(SKIP_PREFIXES):
            continue
        p = Path(path)
        new_name = apply_rules(p.name, Counter())
        if new_name != p.name:
            moves.append((path, (p.parent / new_name).as_posix()))
    for old, new in moves:
        if (ROOT / new).exists():
            print(f"STOP: {new} already exists (moving {old})")
            return 1
        git("mv", old, new)
        print(f"  moved {old} -> {new}")

    # 3. Contents.
    rule_counts, keep_counts, changed = Counter(), Counter(), []
    for path in [p for p in tracked_files() if in_scope(p)]:
        text = read_text(path)
        if text is None:
            continue
        new_text = rename_text(path, text, rule_counts, keep_counts)
        if new_text != text:
            with open(ROOT / path, "w", encoding="utf-8", newline="") as fh:
                fh.write(new_text)
            changed.append(path)

    # 4. Summary.
    print(f"\nFiles moved: {len(moves)}")
    print(f"Files rewritten: {len(changed)}")
    print(f"Replacements: {sum(rule_counts.values())}")
    for rule, n in rule_counts.items():
        print(f"  {n:6d}  {rule}")
    print(f"Kept (masked): {sum(keep_counts.values())}")
    for reason, n in sorted(keep_counts.items()):
        print(f"  {n:6d}  {reason}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
