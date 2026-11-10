#!/bin/sh
# Exercise the real updater with isolated local Git repositories and stub runtimes.
set -eu

source_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
test_root=$(mktemp -d "${TMPDIR:-/tmp}/v2board-update-overwrite.XXXXXX")
trap 'rm -rf "$test_root"' 0
trap 'exit 1' HUP INT TERM

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    if [ -f "$test_root/update.log" ]; then
        cat "$test_root/update.log" >&2
    fi
    exit 1
}

assert_text() {
    [ "$(cat "$1")" = "$2" ] || fail "Unexpected contents in $1"
}

assert_no_deployment() {
    if grep -Eq '^(php |composer |git (fetch|reset)( |$))' "$UPDATE_TEST_CALLS"; then
        fail 'Unexpected deployment command after rejecting arguments'
    fi
}

command -v git >/dev/null 2>&1 || fail 'Git is required'
UPDATE_TEST_REAL_GIT=$(command -v git)
UPDATE_TEST_CALLS="$test_root/commands.log"
export UPDATE_TEST_REAL_GIT UPDATE_TEST_CALLS
mkdir -p "$test_root/bin" "$test_root/publisher"

# These wrappers never connect to a runtime, package server, or database.
cat > "$test_root/bin/git" <<'SH'
#!/bin/sh
printf 'git %s\n' "$*" >> "$UPDATE_TEST_CALLS"
exec "$UPDATE_TEST_REAL_GIT" "$@"
SH
cat > "$test_root/bin/php" <<'SH'
#!/bin/sh
printf 'php %s\n' "$*" >> "$UPDATE_TEST_CALLS"
SH
cat > "$test_root/bin/composer" <<'SH'
#!/bin/sh
printf 'composer %s\n' "$*" >> "$UPDATE_TEST_CALLS"
SH
chmod +x "$test_root/bin/git" "$test_root/bin/php" "$test_root/bin/composer"
PATH="$test_root/bin:$PATH"
export PATH

git init --bare -q "$test_root/origin.git"
git -C "$test_root/publisher" init -q -b master
git -C "$test_root/publisher" config user.name 'V2Board updater regression'
git -C "$test_root/publisher" config user.email 'updater@example.test'
git -C "$test_root/publisher" config core.fileMode true
mkdir -p "$test_root/publisher/scripts" "$test_root/publisher/app" \
    "$test_root/publisher/config/theme" "$test_root/publisher/storage/logs" \
    "$test_root/publisher/bootstrap/cache"
cp "$source_root/update.sh" "$test_root/publisher/update.sh"
cp "$source_root/scripts/composer.sh" "$test_root/publisher/scripts/composer.sh"
cat > "$test_root/publisher/.gitignore" <<'EOF'
.env
/config/v2board.php
EOF
printf '*.php\n!.gitignore\n' > "$test_root/publisher/config/theme/.gitignore"
printf '*\n!.gitignore\n' > "$test_root/publisher/storage/logs/.gitignore"
printf '{}\n' > "$test_root/publisher/composer.lock"
printf '{"name":"test/updater","require":{}}\n' > "$test_root/publisher/composer.json"
printf '<?php\n' > "$test_root/publisher/artisan"
for fixture_file in unstaged staged local-commit conflict; do
    printf 'base\n' > "$test_root/publisher/app/$fixture_file.php"
done
printf '#!/bin/sh\nexit 0\n' > "$test_root/publisher/scripts/executable.sh"
chmod 644 "$test_root/publisher/scripts/executable.sh"
printf 'master\n' > "$test_root/publisher/release-sentinel"
printf 'master only\n' > "$test_root/publisher/master-only"
git -C "$test_root/publisher" add .
git -C "$test_root/publisher" commit -qm 'Fixture master release'
git -C "$test_root/publisher" checkout -qb fix
printf 'fix-v1\n' > "$test_root/publisher/release-sentinel"
rm "$test_root/publisher/master-only"
git -C "$test_root/publisher" add -A
git -C "$test_root/publisher" commit -qm 'Fixture fix release'
git -C "$test_root/publisher" remote add origin "$test_root/origin.git"
git -C "$test_root/publisher" push -q origin master fix
git --git-dir="$test_root/origin.git" symbolic-ref HEAD refs/heads/master
git clone -q --branch fix "$test_root/origin.git" "$test_root/deploy"
git -C "$test_root/deploy" config user.name 'V2Board deployed changes'
git -C "$test_root/deploy" config user.email 'deploy@example.test'
git -C "$test_root/deploy" config core.fileMode true

# Existing installations keep these files outside Git, as in the real project.
printf 'APP_KEY=server-secret\n' > "$test_root/deploy/.env"
printf '<?php return ["server" => true];\n' > "$test_root/deploy/config/v2board.php"
printf '<?php return ["theme" => "custom"];\n' > "$test_root/deploy/config/theme/custom.php"
printf 'server log\n' > "$test_root/deploy/storage/logs/runtime.log"
printf 'server local file\n' > "$test_root/deploy/local-notes.txt"

# Combine divergent commits, conflicting content, staged and unstaged changes,
# and a local execution-bit change in one ordinary default update.
printf 'server commit\n' > "$test_root/deploy/app/local-commit.php"
printf 'server conflict\n' > "$test_root/deploy/app/conflict.php"
git -C "$test_root/deploy" add app/local-commit.php app/conflict.php
git -C "$test_root/deploy" commit -qm 'Local deployed commit'
local_commit=$(git -C "$test_root/deploy" rev-parse HEAD)
printf 'server unstaged\n' > "$test_root/deploy/app/unstaged.php"
printf 'server staged\n' > "$test_root/deploy/app/staged.php"
git -C "$test_root/deploy" add app/staged.php
chmod +x "$test_root/deploy/scripts/executable.sh"

for fixture_file in unstaged staged local-commit conflict; do
    printf 'upstream-v2\n' > "$test_root/publisher/app/$fixture_file.php"
done
printf 'fix-v2\n' > "$test_root/publisher/release-sentinel"
# Keep the actual script, adding a marker to prove the downloaded copy is loaded.
{
    printf '#!/bin/sh\n'
    printf 'printf "release-script-loaded\\n" >> "$UPDATE_TEST_CALLS"\n'
    sed '1d' "$source_root/update.sh"
} > "$test_root/publisher/update.sh"
git -C "$test_root/publisher" add .
git -C "$test_root/publisher" commit -qm 'Updated fix release'
git -C "$test_root/publisher" push -q origin fix
remote_commit=$(git -C "$test_root/publisher" rev-parse HEAD)
: > "$UPDATE_TEST_CALLS"
if ! (cd "$test_root/deploy" && sh ./update.sh) > "$test_root/update.log" 2>&1; then
    fail 'Default update failed with modified tracked files or divergent commits'
fi
[ "$(git -C "$test_root/deploy" rev-parse HEAD)" = "$remote_commit" ] || fail 'HEAD does not match upstream fix'
[ "$(git -C "$test_root/deploy" branch --show-current)" = fix ] || fail 'Update changed the deployed branch'
[ ! -e "$test_root/deploy/master-only" ] || fail 'Update selected master instead of fix'
if git -C "$test_root/deploy" merge-base --is-ancestor "$local_commit" HEAD; then
    fail 'Local divergent commit survived the overwrite'
fi
for fixture_file in unstaged staged local-commit conflict; do
    assert_text "$test_root/deploy/app/$fixture_file.php" upstream-v2
done
[ ! -x "$test_root/deploy/scripts/executable.sh" ] || fail 'Local executable-bit change survived'
[ -z "$(git -C "$test_root/deploy" status --porcelain --untracked-files=no)" ] || fail 'Tracked changes survived the overwrite'
assert_text "$test_root/deploy/release-sentinel" fix-v2
assert_text "$test_root/deploy/.env" APP_KEY=server-secret
assert_text "$test_root/deploy/config/v2board.php" '<?php return ["server" => true];'
assert_text "$test_root/deploy/config/theme/custom.php" '<?php return ["theme" => "custom"];'
assert_text "$test_root/deploy/storage/logs/runtime.log" 'server log'
assert_text "$test_root/deploy/local-notes.txt" 'server local file'
grep -q '^release-script-loaded$' "$UPDATE_TEST_CALLS" || fail 'Downloaded update script was not reloaded'
grep -q '^composer validate ' "$UPDATE_TEST_CALLS" || fail 'Composer validation was not invoked'
grep -q '^composer install ' "$UPDATE_TEST_CALLS" || fail 'Dependency installation was not invoked'
grep -q '^php artisan v2board:update$' "$UPDATE_TEST_CALLS" || fail 'Database updater was not invoked'
printf 'PASS: Default update overwrites tracked changes and local commits, follows fix, preserves runtime files, and reloads the new script\n'

# --local runs only the local deployment steps, even with a newer remote release.
printf 'fix-v3\n' > "$test_root/publisher/release-sentinel"
git -C "$test_root/publisher" add release-sentinel
git -C "$test_root/publisher" commit -qm 'Pending fix release'
git -C "$test_root/publisher" push -q origin fix
printf 'keep local unstaged\n' > "$test_root/deploy/app/unstaged.php"
printf 'keep local staged\n' > "$test_root/deploy/app/staged.php"
git -C "$test_root/deploy" add app/staged.php
local_head=$(git -C "$test_root/deploy" rev-parse HEAD)
local_upstream=$(git -C "$test_root/deploy" rev-parse origin/fix)
local_status=$(git -C "$test_root/deploy" status --porcelain --untracked-files=no)
: > "$UPDATE_TEST_CALLS"
if ! (cd "$test_root/deploy" && sh ./update.sh --local) > "$test_root/update.log" 2>&1; then
    fail '--local deployment failed'
fi
if grep -Eq '^git (fetch|reset)( |$)' "$UPDATE_TEST_CALLS"; then
    fail '--local fetched or reset the repository'
fi
[ "$(git -C "$test_root/deploy" rev-parse HEAD)" = "$local_head" ] || fail '--local changed HEAD'
[ "$(git -C "$test_root/deploy" rev-parse origin/fix)" = "$local_upstream" ] || fail '--local refreshed the remote branch'
[ "$(git -C "$test_root/deploy" status --porcelain --untracked-files=no)" = "$local_status" ] || fail '--local changed tracked work'
assert_text "$test_root/deploy/app/unstaged.php" 'keep local unstaged'
assert_text "$test_root/deploy/app/staged.php" 'keep local staged'
assert_text "$test_root/deploy/release-sentinel" fix-v2
grep -q '^php artisan v2board:update$' "$UPDATE_TEST_CALLS" || fail '--local did not run database updater'
printf 'PASS: --local runs deployment without fetching, resetting, or overwriting tracked changes\n'

: > "$UPDATE_TEST_CALLS"
if (cd "$test_root/deploy" && sh ./update.sh --invalid-option) > "$test_root/update.log" 2>&1; then
    fail 'Unknown update option unexpectedly succeeded'
fi
assert_no_deployment
printf 'PASS: Unknown arguments fail before deployment\n'

# A failed fetch must not erase local work or invoke dependency/database steps.
git -C "$test_root/deploy" remote set-url origin "$test_root/missing-origin.git"
: > "$UPDATE_TEST_CALLS"
if (cd "$test_root/deploy" && sh ./update.sh) > "$test_root/update.log" 2>&1; then
    fail 'Update unexpectedly succeeded with an inaccessible remote'
fi
grep -q '^git fetch ' "$UPDATE_TEST_CALLS" || fail 'Fetch failure scenario did not attempt fetch'
if grep -Eq '^(php |composer |git reset( |$))' "$UPDATE_TEST_CALLS"; then
    fail 'Fetch failure reset local work or continued deployment'
fi
[ "$(git -C "$test_root/deploy" rev-parse HEAD)" = "$local_head" ] || fail 'Fetch failure changed HEAD'
[ "$(git -C "$test_root/deploy" status --porcelain --untracked-files=no)" = "$local_status" ] || fail 'Fetch failure changed tracked work'
assert_text "$test_root/deploy/app/unstaged.php" 'keep local unstaged'
assert_text "$test_root/deploy/app/staged.php" 'keep local staged'
printf 'PASS: Failed fetch preserves local work and stops before reset or deployment\n'
printf 'PASS: All isolated updater overwrite regressions passed\n'
