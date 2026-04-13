# PMU Pronostics

Application Symfony + Vue.js pour importer des donnees hippiques, gerer le referentiel (courses, chevaux, personnes, hippodromes, participations), generer des pronostics, puis mesurer la qualite des predictions via des KPI.

## Fonctionnalites principales

- Gestion back-office des entites metier:
  - personnes
  - chevaux
  - hippodromes
  - courses
  - participations
- Import de donnees `.xlsx` via commande CLI (`app:import:races`) avec mode `--dry-run`.
- Normalisation des noms (alias chevaux/personnes) avec suggestions de similarite.
- Calcul de pronostics par course avec 2 modes de scoring:
  - `conservative`
  - `aggressive`
- Snapshot des predictions et comparaison automatique avec les resultats reels.
- Dashboard KPI (couverture, top1, top3, erreur moyenne, NDCG@5).
- Exports CSV:
  - pronostic d'une course
  - dashboard KPI
- Endpoint JSON pour recuperer un pronostic de course.

## Evolution cible: IA avancee et restitution utilisateur

### 1) Machine Learning (avance)

Si le volume de donnees devient important, une approche supervisee peut completer le scoring actuel:

- Entrainer un modele predictif (ex: XGBoost, reseau de neurones) sur des milliers de courses historiques.
- Donner au modele les caracteristiques de course (distance, terrain, hippodrome, forme recente, jockey/driver, etc.) et le resultat reel.
- Laisser l algorithme apprendre les correlations utiles (ex: performance elevee d un jockey sur un type de distance).
- Produire, pour les courses du jour, une probabilite de victoire et un classement des partants.

### 2) Experience utilisateur (restitution)

La qualite du pronostic ne suffit pas: l utilisateur doit comprendre le resultat pour lui faire confiance.

- Classement suggere:
  - Afficher le pronostic sous forme de Top 3 ou Top 5 des favoris.
- Indice de confiance:
  - Afficher une jauge ou un pourcentage (ex: Confiance 85% ou note en etoiles).
  - Si la course est trop imprevisible (donnees insuffisantes ou signaux contradictoires), abaisser explicitement cet indice.
- Explication textuelle (le "pourquoi"):
  - Generer une phrase courte qui justifie le rang propose.
  - Exemple: "Notre algorithme place le n 4 en tete grace a ses performances recentes sur cette distance et son affinite avec les terrains lourds."

## Stack technique

- PHP `>= 8.2`
- Symfony `7.4`
- Doctrine ORM + Migrations
- PostgreSQL `16`
- Webpack Encore + Vue `3`
- PHPUnit `11`
- Docker Compose (services `app`, `node`, `database`)

## Prerequis

### Option A (recommande): Docker

- Docker Desktop
- Docker Compose

### Option B: Execution locale

- PHP `>= 8.2`
- Composer
- Node.js + npm
- PostgreSQL `16`

## Demarrage rapide (Docker)

1. Lancer les services:

```bash
docker compose up --build
```

2. Appliquer les migrations (dans le conteneur PHP):

```bash
docker compose exec app php bin/console doctrine:migrations:migrate -n
```

3. Ouvrir l'application:

- App principale: http://localhost:8000/
- Gestion: http://localhost:8000/gestion

Notes:
- Le service `app` installe automatiquement les dependances PHP au demarrage.
- Le service `node` installe les dependances front et lance `npm run watch`.

## Demarrage local (sans Docker)

1. Installer les dependances:

```bash
composer install
npm install
```

2. Verifier la base PostgreSQL et la variable `DATABASE_URL` (dans `.env` ou `.env.local`).

Exemple par defaut:

```env
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"
```

3. Creer le schema via migrations:

```bash
php bin/console doctrine:migrations:migrate -n
```

4. Lancer le serveur Symfony:

```bash
php -S 0.0.0.0:8000 -t public
```

5. Lancer le front (watch):

```bash
npm run watch
```

## Commandes utiles

### Tests

```bash
php bin/phpunit
```

### Machine learning

Entrainer le modele de probabilite de victoire depuis les courses terminees:

```bash
php bin/console app:ml:train-win-model
```

Le modele est persiste dans `var/ml/win_probability_model.json` et est utilise automatiquement dans le scoring quand il est disponible.

### Build des assets

```bash
npm run dev
npm run build
```

### Import Excel

Import reel:

```bash
php bin/console app:import:races chemin/vers/fichier.xlsx
```

Validation sans ecriture:

```bash
php bin/console app:import:races chemin/vers/fichier.xlsx --dry-run
```

### Scraping Web (donnees a jour)

Configurer une ou plusieurs sources dans `config/packages/race_scraper.yaml` avec:

- `url`
- `selectors.race.*` (infos course)
- `selectors.participants.row` + champs participants

Exemple d import depuis une source configuree:

```bash
php bin/console app:scrape:races exemple_site
```

Exemple avec URL directe:

```bash
php bin/console app:scrape:races --url="https://example.com/races/reunion-1-course-1"
```

Mode validation (sans ecriture base):

```bash
php bin/console app:scrape:races exemple_site --dry-run
```

Override manuel des metadonnees course (utile si le site ne les expose pas proprement):

```bash
php bin/console app:scrape:races exemple_site --hippodrome="VINCENNES" --meeting=1 --race=4 --date=2026-04-13
```

### Scraping Letrot (reunion + course)

Source preconfiguree: `letrot`.

Depuis une URL de programme reunion (choix de la course avec `--letrot-course`):

```bash
php bin/console app:scrape:races letrot --url="https://www.letrot.com/courses/programme/2026-04-13/6102" --letrot-course=1 --dry-run
```

Depuis une URL directe de course:

```bash
php bin/console app:scrape:races letrot --url="https://www.letrot.com/courses/2026-04-13/6102/1" --dry-run
```

La commande affiche aussi le lien PDF de la reunion s il est detecte.

Pipeline automatise (import + controle qualite):

```bash
php bin/console app:automation:letrot-sync --date=2026-04-13
```

Mode validation (sans ecriture):

```bash
php bin/console app:automation:letrot-sync --date=2026-04-13 --dry-run --letrot-limit-meetings=1 --letrot-limit-races=1
```

Le pipeline retourne un code erreur si la confiance qualite est sous le seuil configure.

```bash
php bin/console app:automation:letrot-sync --date=2026-04-13 --quality-threshold=0.80
```

Scripts Windows (Task Scheduler):

```powershell
# Lance le pipeline une fois
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\run-letrot-sync.ps1

# Cree 2 taches quotidiennes (matin/soir)
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\register-letrot-tasks.ps1
```

Mode automatique (sans saisir les URL une par une):

```bash
php bin/console app:scrape:races letrot --letrot-auto --dry-run
```

Par defaut, une course est ignoree si son payload Letrot est inchange (hash identique), ce qui accelere fortement les relances.

Forcer la reimportation d une course deja chargee:

```bash
php bin/console app:scrape:races letrot --letrot-auto --letrot-date=2026-04-13 --force-reimport --dry-run
```

Mode automatique sur une date precise:

```bash
php bin/console app:scrape:races letrot --letrot-auto --letrot-date=2026-04-13 --dry-run
```

Limiter le volume (pratique pour tester):

```bash
php bin/console app:scrape:races letrot --letrot-auto --letrot-date=2026-04-13 --letrot-limit-meetings=1 --letrot-limit-races=2 --dry-run
```

### Migrations Doctrine

```bash
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:migrate -n
```

## Routes utiles

- `/` : accueil
- `/gestion` : tableau de bord gestion
- `/gestion/personnes`
- `/gestion/chevaux`
- `/gestion/hippodromes`
- `/gestion/courses`
- `/gestion/participations`
- `/gestion/imports`
- `/gestion/normalisation`
- `/gestion/dashboard`
- `/pronostic/{id}?mode=conservative|aggressive` : endpoint JSON

## Structure du projet (resume)

- `src/Controller/` : pages de gestion + endpoint pronostic
- `src/Service/` : import, scoring, snapshots, comparaison, KPI, export CSV
- `src/Entity/` : modele de donnees Doctrine
- `templates/` : vues Twig
- `assets/` : front Vue + CSS
- `migrations/` : migrations Doctrine
- `tests/` : tests unitaires et fonctionnels

## Depannage rapide

- Erreur de table manquante:
  - executer `php bin/console doctrine:migrations:migrate -n`
- Assets non charges:
  - verifier que `npm run watch` (ou `npm run dev`) tourne
- Erreur de connexion DB:
  - verifier `DATABASE_URL` et que PostgreSQL est disponible

## Licence

Projet proprietaire (voir `composer.json` et `package.json`).
