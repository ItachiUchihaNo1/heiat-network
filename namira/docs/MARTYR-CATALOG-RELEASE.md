# Martyr bank and taxonomy release — 2026-10-27

Scope: martyr creation/editing, central taxonomy, geography and public discovery only. Production main site is excluded.

## Install and upgrade
Use the existing PHP/MySQL setup and environment configuration. Deploy this release only to test.shahidpics.com, document root Public_html/new. Sign in as the existing super administrator and open the administration panel. AdminPanel::boot executes migration 005 after 004, under an exclusive lock. Before DDL it writes a private SQL backup and counts report to shared storage/backups. An incomplete migration may be retried; the marker is written last. Never expose storage or backups through the web.

Four additive tables: martyr_axis_settings, martyr_term_redirects, martyr_public_facts, martyr_public_terms. Existing identity IDs, raw legacy text, evidence states, original image files, relationships and publication snapshots are retained. Unmatched legacy geography and occupations are listed in the private migration report; no personal record is assigned a guessed meaning. Administrators must review these manually.

Rollback: preserve the pre-upgrade SQL backup and matching original media storage. Restore into an isolated database first and compare the recorded table counts. Reverting application code alone does not reverse a database migration. New tables can remain unused by the preceding application; do not drop them or overwrite post-upgrade edits without a reviewed restore plan. Term merges are independently reversible in the taxonomy manager.

## Editing
Create a file with code, first and last name, then complete its sections progressively. Omitted fields and their evidence states are retained; explicit presence markers distinguish intentionally cleared fields. Revision guards reject stale saves. Advanced evidence controls are optional and collapsed. Legacy free text remains visible separately. Publication still requires the established review workflow.

Category axes and terms use stable identifiers. Built-in semantic bindings remain distinct. Custom axes can be created centrally; form visibility, public filter visibility, order and selection mode are configurable. Used semantic bindings cannot be silently repurposed. Merge records redirect lookup while preserving original relationships; geographic merges require manual review rather than automatic conversion.

## Gallery filter policy — updated 2026-10-27
The gallery sidebar shows exactly the active public martyr axes configured in the central manager, in the configured order, plus the date/year filter. Empty axes retain their titles with an explicit empty state. Published-value isolation remains enforced. Legacy categories, standalone geography, orientation and sorting controls are removed. Their query parameters are ignored on the gallery route, preventing hidden restrictions from older URLs. The shared category-pill links to those removed filters are also removed. Global header search remains available. No migration or personal-data mapping is performed by this update.

## Public filter data
Only approved publication snapshots build the public term/geography/year indexes. Draft changes do not replace these indexes. Values within an axis use OR; axes use AND. All selected martyr properties must belong to the same martyr related to the image. Images are returned once even when several related martyrs qualify. Legacy raw-text conditions remain available to internal archive operations; the public gallery route no longer accepts them. Time filtering uses known years in the selected calendar; it does not infer missing dates or convert calendars.

## Geography attribution and limits
The packaged reference contains 31 provinces, 484 counties and 1481 cities, source year 1404. Source: https://github.com/sajaddp/list-of-cities-in-Iran at commit ace21bb251f96ced350bbc01541acffd71121feb (dist/json/provinces.json, counties.json, cities-filtered.json). Included GPL-3.0 license: licenses/iran-geography-GPL-3.0.txt. The catalog excludes urban zones, villages and coordinates; missing places can be managed by an authorized administrator. It is a reference dataset, not proof of a personal location or a guarantee of current administrative boundaries.

## Verification
Run php tests/martyr-catalog.php for the 72 dedicated checks, plus existing run.php, protocol.php, platform.php, community.php, installer.php, large-images.php and release-paths.php. Run node tests/upload-progress.cjs, node tests/image-previews.cjs and python tests/deploy.py. CI repeats database suites on MySQL 8.4 using isolated databases and verifies SQL restore. Browser fixtures are synthetic; never test writes against real martyr files.

Dedicated checks cover quick creation, true rendered form serialization and reload, partial edits, explicit clears, nested dates/places, multiple records, permissions, migration counts/backup/restore, taxonomy creation/rename/merge/undo, frozen publication indexes, combined image filters, same-person matching and strict view rendering.

Merging a term with children is blocked until its hierarchy is separately reviewed, so redirects cannot orphan descendants.

Gallery filter groups use one column at mobile/tablet widths so an expanded group does not stretch adjacent groups or action buttons.
