# Gestion News (Nuxt Client-Side)

Interface d'administration pour piloter l'API Laravel dans `extract_news/api`.

## Fonctionnalites

- Login reel via API Laravel (Sanctum token)
- Liste des news avec filtres et tri
- Selection unitaire/multiple
- Actions endpoint manuelles:
  - Sync WordPress
  - Process Emails
  - Update statut single
  - Update statut bulk (selected / filtered / all)
  - Post WordPress single
  - Post WordPress bulk (selected / filtered / all)
  - Preview article

## Installation

1. Copier l'environnement:

```bash
cp .env.example .env
```

2. Installer les dependances:

```bash
npm install
```

3. Lancer le front:

```bash
npm run dev
```

## Variables

- `NUXT_PUBLIC_API_BASE_URL`: Base URL de l'API Laravel

## Important

Le login passe par les endpoints Laravel `/auth/login`, `/auth/me`, `/auth/logout`.
Le token est stocke localement et ajoute automatiquement en `Authorization: Bearer ...`.
