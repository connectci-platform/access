# Test Plan: `access_content_api` missing coverage

Tracks the gap between the test suite the PRD specifies and what currently
exists in `tests/src/Kernel/`. Unit tests (`TextExtractorTest`) are complete and
not covered here.

## Current state

**`ContentEndpointTest`** (per-page endpoint) — already covered:
- Happy path: published page on the support domain returns 200 + extracted text
- 404 for unknown ID
- 404 for unpublished node
- 404 for a node with no domain assignment
- 404 for an unknown path alias

**`ContentIndexTest`** (discovery index) — already covered:
- Returns valid JSON with the expected top-level fields
- A weak "published only" check (see note below)

## Shared groundwork (do this first)

Both endpoint tests need a node that actually qualifies before most of the
missing tests can assert anything real. `ContentEndpointTest::setUp()` already
builds this; `ContentIndexTest::setUp()` does **not** and must be brought up to
the same baseline. Extract the common setup so both classes share it:

- Add the `body` field to the `page` type.
- Run the Domain Access install hook so `field_domain_access` exists.
- Create a permissive text format so body HTML survives to the extractor.
- Create the `text` view mode + `node.page.text` display (idempotent guard —
  the module auto-installs the view mode on enable).
- Create the support `Domain` entity matching the controller's domain ID.

A small `createPage(array $overrides)` helper (published, on the support domain,
with body) will keep the individual tests short.

## Missing tests — per-page endpoint (`ContentEndpointTest`)

| Test | What it proves |
|---|---|
| `testReturns404ForUnsupportedContentType` | A content type with no `text` view mode is rejected (404). |
| `testReturns404ForOtherDomainNode` | A node assigned to a *different* domain (not just no domain) is rejected. |
| `testShortcodeExpansionInBody` | Body shortcodes (e.g. an accordion token) come back as real text, not the raw token. |
| `testPathByQueryResolvesAlias` | Looking up by `?path=/alias` returns the same result as looking up by ID. |
| `testIfNoneMatchReturns304` | A repeat request with a matching `If-None-Match` returns 304 with no body. |
| `testCacheInvalidatesOnNodeSave` | Fetch, edit the body, fetch again — the second response reflects the edit. |
| `testLayoutBuilderWalkerRendersMultipleComponents` | A page built from multiple layout blocks returns text from all of them. |
| `testDenylistedComponentsAreSkipped` | A denylisted component (e.g. a views block) does not appear in the output. |

## Missing tests — discovery index (`ContentIndexTest`)

| Test | What it proves |
|---|---|
| `testIndexExcludesOtherDomainNodes` | Pages on other domains are left out of the index. |
| `testIndexExcludesUnsupportedContentTypes` | Only types with a `text` view mode are listed. |
| `testIndexSortedByPathAlias` | Entries are sorted by path alias, ascending and stable. |
| `testIndexInvalidatesOnPageSave` | Saving a new qualifying page shows up on the next fetch. |

Also strengthen the existing `testIndexListsPublishedNodesOnly`: once the shared
groundwork is in place, it should create one published and one unpublished page
and assert the published one **is** present and the unpublished one is **not**
(today it only checks the response is an array).

## Suggested order

1. Extract shared setup + `createPage()` helper; upgrade `ContentIndexTest::setUp()`.
2. Strengthen `testIndexListsPublishedNodesOnly`.
3. Add the straightforward filter/lookup tests (unsupported type, other domain,
   path-by-alias, index exclusions, index sorting).
4. Add the caching tests (304, invalidation on save, index invalidation).
5. Add the Layout Builder tests (multi-component render, denylist) last — they
   need the most setup.

## How to run

```bash
ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core \
  web/modules/custom/access/modules/access_content_api/tests/src/Kernel"
```

Definition of done: every row above implemented and green, plus the strengthened
published-only index test.
