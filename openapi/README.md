# ACCESS OpenAPI Specifications

This directory contains the OpenAPI 3.0.3 specifications for the ACCESS APIs.

## Files

- `announcements-api-2.2-openapi.yaml` - Announcements API v2.2 specification (YAML format)
- `announcements-api-2.2-openapi.json` - Announcements API v2.2 specification (JSON format)
- `announcements-api-2.3-openapi.yaml` - Announcements API v2.3 specification (YAML format) — acting-user authoring endpoints
- `events-api-2.2-openapi.yaml` - Events API v2.2 specification (YAML format)
- `events-api-2.2-openapi.json` - Events API v2.2 specification (JSON format)
- `events-api-2.3-openapi.yaml` - Events READ API v2.3 specification (YAML format) — public listing plus the event detail endpoint (detail's registration block is partitioned by caller: anonymous vs authenticated acting-user)
- `event-registration-api-1.0-openapi.yaml` - Event Registration API v1.0 specification (YAML format) — authenticated, acting-user registration endpoints

## Usage

These specifications are automatically loaded by the OpenAPI generator plugins:

- `AccessAnnouncementsGenerator` - Serves announcements spec at `/openapi/access_announcements`
- `AccessAnnouncementsV23Generator` - Serves announcements v2.3 spec at `/openapi/access_announcements_v23`
- `AccessEventsGenerator` - Serves events spec at `/openapi/access_events`
- `AccessEventsV23Generator` - Serves events v2.3 spec at `/openapi/access_events_v23`
- `AccessEventRegistrationGenerator` - Serves event registration v1.0 spec at `/openapi/access_event_registration`

## Endpoints

- **Announcements API**: `/api/2.2/announcements`
- **Announcements API (v2.3, acting-user authoring)**: `/api/2.3/announcements`
  - `POST /api/2.3/announcements` - create a draft announcement
  - `PATCH /api/2.3/announcements/{uuid}` - update an announcement
  - `DELETE /api/2.3/announcements/{uuid}` - delete an announcement
  - `GET /api/2.3/announcements/mine` - list the acting user's own announcements
- **Events API**: `/api/2.2/events`
- **Events READ API (v2.3, public)**: `/api/2.3/events`
  - `GET /api/2.3/events` - public events listing
  - `GET /api/2.3/events/{eventinstance}` - event detail with registration state (public read; the registration block is partitioned by caller — anonymous callers omit `registration_open`, `registration_window`, and `already_registered`, which are returned only to authenticated acting-user callers)
- **Event Registration API (v1.0, authenticated acting-user)**: `/api/1.0`
  - `POST /api/1.0/events/{eventinstance}/register` - register (preview or commit)
  - `GET /api/1.0/registrations` - list the acting user's own registrations
  - `DELETE /api/1.0/registrations/{registrant_id}` - cancel a registration

## OpenAPI Documentation

- **JSON endpoints**: 
  - `/openapi/access_announcements`
  - `/openapi/access_announcements_v23`
  - `/openapi/access_events`
  - `/openapi/access_events_v23`
  - `/openapi/access_event_registration`

- **Swagger UI**:
  - `/admin/config/services/openapi/swagger/access_announcements`
  - `/admin/config/services/openapi/swagger/access_announcements_v23`
  - `/admin/config/services/openapi/swagger/access_events`
  - `/admin/config/services/openapi/swagger/access_events_v23`
  - `/admin/config/services/openapi/swagger/access_event_registration`

## Maintenance

To update these specifications:

1. Edit the YAML or JSON files directly
2. Clear Drupal cache: `ddev drush cr`
3. The changes will be immediately reflected in the OpenAPI endpoints

## Version Control

These files are now version-controlled as part of the ACCESS module, ensuring they are:
- Backed up in git
- Deployed automatically
- Maintained alongside the codebase