#!/usr/bin/env bash
set -euo pipefail

readonly script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly repository_root="$(cd -- "$script_dir/../../../.." && pwd)"

if [[ $# -gt 0 ]]; then
  scan_files=("$@")
else
  scan_files=()
  while IFS= read -r path; do
    [[ -n "$path" ]] && scan_files+=("$path")
  done < <(
    {
      git -C "$repository_root" diff --name-only --cached
      git -C "$repository_root" diff --name-only
      git -C "$repository_root" ls-files --others --exclude-standard
    } | sort -u
  )
fi

if [[ ${#scan_files[@]} -eq 0 ]]; then
  printf 'SECRET_SCAN: PASS\n'
  exit 0
fi

filtered_files=()
for relative_path in "${scan_files[@]}"; do
  relative_path="${relative_path#./}"
  if [[ "$relative_path" == docs/70_UAT/* ]]; then
    filtered_files+=("$relative_path")
  elif [[ $# -gt 0 ]]; then
    printf '%s: FORBIDDEN_PATH\n' "$relative_path"
    exit 1
  fi
done
scan_files=("${filtered_files[@]-}")

if [[ ${#scan_files[@]} -eq 0 || -z "${scan_files[0]}" ]]; then
  printf 'SECRET_SCAN: PASS\n'
  exit 0
fi

report_forbidden() {
  local path="$1"
  printf '%s: FORBIDDEN_PATH\n' "$path"
}

report_secret() {
  local path="$1"
  local line_number="$2"
  local rule="$3"
  printf '%s:%s: %s\n' "$path" "$line_number" "$rule"
}

status=0
for relative_path in "${scan_files[@]}"; do
  [[ -n "$relative_path" ]] || continue
  relative_path="${relative_path#./}"

  if [[ ! -f "$repository_root/$relative_path" ]]; then
    report_forbidden "$relative_path"
    status=1
    continue
  fi

  case "$relative_path" in
    .opencode/skills/byl-erp-uat-recorder/*|docs/70_UAT/*)
      ;;
    *)
      report_forbidden "$relative_path"
      status=1
      continue
      ;;
  esac

  case "$relative_path" in
    .env|.env.*|*/.env|*/.env.*|.codex/byl-erp-local-uat-access.txt|*.sql|*.sqlite|*.sqlite3|*.db|storage/*|*/storage/*|vendor/*|*/vendor/*|node_modules/*|*/node_modules/*|*.php|*.inc|*.conf|*.ini|*.yaml|*.yml|*.json)
      report_forbidden "$relative_path"
      status=1
      continue
      ;;
    docs/70_UAT/evidence/*)
      if [[ ! "$relative_path" =~ ^docs/70_UAT/evidence/[0-9]{4}-[0-9]{2}-[0-9]{2}/[^/]+$ ]]; then
        report_forbidden "$relative_path"
        status=1
        continue
      fi
      case "$relative_path" in
        *.png|*.jpg|*.jpeg|*.webp)
          ;;
        *)
          report_forbidden "$relative_path"
          status=1
          continue
          ;;
      esac
      ;;
  esac

  if [[ ! -r "$repository_root/$relative_path" ]]; then
    printf '%s: SCAN_CONFIGURATION_ERROR\n' "$relative_path"
    status=2
    continue
  fi

  scan_path="$repository_root/$relative_path"
  if [[ ! -s "$scan_path" ]]; then
    continue
  fi

  while IFS=: read -r line_number rule; do
    [[ -n "$line_number" ]] || continue
    report_secret "$relative_path" "$line_number" "$rule"
    status=1
  done < <(
    rg -n -i -o \
      '(-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----|AKIA[0-9A-Z]{16}|(ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}|Bearer[[:space:]]+[A-Za-z0-9._~+/=-]{20,}|eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}|Basic[[:space:]]+[A-Za-z0-9+/=]{12,}|(Cookie|Set-Cookie):[[:space:]]*[^[:space:]]+|(session(id)?|sid|connect\.sid)[[:space:]]*[:=][[:space:]]*[^[:space:]]+|(api[_-]?secret|secret[_-]?key|client[_-]?secret|db[_-]?password|database[_-]?password|password)[[:space:]]*[:=][[:space:]]*[^[:space:]<>{}][^[:space:]]*)' \
      "$scan_path" \
      | while IFS=: read -r line_number _match; do
          if [[ "$_match" == *PRIVATE*KEY* ]]; then
            printf '%s: PEM_PRIVATE_KEY_HEADER\n' "$line_number"
          elif [[ "$_match" == AKIA* ]]; then
            printf '%s: AWS_ACCESS_KEY\n' "$line_number"
          elif [[ "$_match" == ghp_* || "$_match" == gho_* || "$_match" == github_pat_* ]]; then
            printf '%s: GITHUB_TOKEN\n' "$line_number"
          elif [[ "$_match" == Bearer* ]]; then
            printf '%s: BEARER_TOKEN\n' "$line_number"
          elif [[ "$_match" == eyJ* ]]; then
            printf '%s: JWT_LIKE_TOKEN\n' "$line_number"
          elif [[ "$_match" == Basic* ]]; then
            printf '%s: BASIC_AUTHORIZATION\n' "$line_number"
          elif [[ "$_match" == Cookie:* || "$_match" == Set-Cookie:* ]]; then
            printf '%s: COOKIE_VALUE\n' "$line_number"
          elif [[ "$_match" == session* || "$_match" == sid* || "$_match" == connect.sid* ]]; then
            printf '%s: SESSION_IDENTIFIER\n' "$line_number"
          else
            printf '%s: SECRET_ASSIGNMENT\n' "$line_number"
          fi
        done
  )
done

if command -v detect-secrets >/dev/null 2>&1; then
  detect_secrets_output="$(detect-secrets scan --no-verify "${scan_files[@]/#/$repository_root/}" 2>/dev/null || true)"
  if [[ "$detect_secrets_output" == *'"results": {'* && "$detect_secrets_output" != *'"results": {}'* ]]; then
    printf '%s: DETECT_SECRETS_MATCH\n' '.scan'
    status=1
  fi
fi

if [[ $status -eq 0 ]]; then
  printf 'SECRET_SCAN: PASS\n'
  exit 0
fi

if [[ $status -eq 2 ]]; then
  exit 2
fi
exit 1
