#!/bin/sh
# pg_restore through the dbx-pg docker container, for BackupTest's restore
# round-trip: the counterpart of docker-pgdump.sh. Drops --host/--port (the
# server is local inside the container), feeds the archive (the last
# argument) through stdin, and passes PGPASSWORD through docker exec -e.
real=""
skip=""
file=""

for a in "$@"; do
    if [ -n "$skip" ]; then
        skip=""
        continue
    fi
    case "$a" in
        --version|-V) exec docker exec -i dbx-pg pg_restore --version ;;
        --host|--port) skip=1 ;;
        *)
            [ -n "$file" ] && real="$real \"$file\""
            file="$a"
            ;;
    esac
done
if [ -z "$file" ] || [ ! -f "$file" ]; then
    echo "docker-pgrestore: the last argument must be the archive" >&2
    exit 2
fi
exec docker exec -i -e PGPASSWORD="${PGPASSWORD:-}" dbx-pg sh -c "pg_restore $real" < "$file"
