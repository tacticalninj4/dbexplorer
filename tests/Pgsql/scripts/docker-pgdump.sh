#!/bin/sh
# pg_dump through the dbx-pg docker container, for BackupTest: the only
# pg_dump guaranteed to match the server's version. Drops --host/--port
# (the server is local inside the container), turns --file PATH into an
# stdout redirect, and passes PGPASSWORD through docker exec -e.
out=""
real=""
mode=""

# Version probes and anything without --file run straight through.
for a in "$@"; do
    case "$a" in
        --version|-V) exec docker exec -i dbx-pg pg_dump "$@" ;;
    esac
done

for a in "$@"; do
    if [ -n "$mode" ]; then
        [ "$mode" = "f" ] && out="$a"
        mode=""
        continue
    fi
    case "$a" in
        --file) mode="f" ;;
        --host|--port) mode="s" ;;
        *) real="$real \"$a\"" ;;
    esac
done
if [ -z "$out" ]; then
    echo "docker-pgdump: no --file given" >&2
    exit 2
fi
exec docker exec -i -e PGPASSWORD="${PGPASSWORD:-}" dbx-pg sh -c "pg_dump $real" > "$out"
