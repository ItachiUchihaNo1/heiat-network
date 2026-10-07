#!/usr/bin/env sh
set -eu

if [ ! -f .env ]; then
  echo ".env not found. Copy .env.example to .env and edit it first."
  exit 1
fi

docker compose up -d db
docker compose build app
docker compose run --rm app npx prisma db push
docker compose run --rm app npm run db:seed
docker compose up -d

echo "Deployment started. Check: docker compose ps"
