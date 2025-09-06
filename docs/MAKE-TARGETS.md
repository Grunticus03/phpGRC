# Make targets

Run from repo root.

## Quick start
```
make install key migrate serve
```

## Targets

### prepare
Create runtime dirs and copy `.env` if missing.
```
make prepare
```

### install
Composer install and prepare.
```
make install
```

### key
Generate `APP_KEY`.
```
make key
```

### routes
List API routes.
```
make routes
```

### migrate / migrate-fresh / rollback
Apply, rebuild, or roll back DB schema.
```
make migrate
make migrate-fresh
make rollback
```

### qa
Run Pint, PHPStan, and Psalm.
```
make qa
```

### test
Run PHPUnit.
```
make test
```

### serve
Run local PHP server at `phpgrc.gruntlabs.net:9000`.
```
make serve
```

### smoke
Health check and sample evidence upload.
```
make smoke
```

### db-create / db-drop
Create or drop the `phpgrc` database and user (requires MySQL root).
```
make db-create
make db-drop
```