<?php

declare(strict_types=1);

/*
 * The most important test in the project.
 *
 * CORPUS FORMAT (one JSON file per request, see tests/corpus at the repo root):
 * {
 *   "description": "what this request is",
 *   "request": { "method": "GET", "path": "/.env", "query": {},
 *                "headers": {}, "body": "", "ip": "203.0.113.9" },
 *   "expect": { "rules": ["sensitive-path"] }
 * }
 * Files in attacks/ must trigger every rule listed in expect.rules.
 * Files in normal/ must trigger nothing (expect.rules is []).
 *
 * IMPLEMENTATION NOTES
 * - Corpus root from this file: __DIR__ . '/../../../tests/corpus'.
 * - Build the Engine once per case: all rules, ArrayStore, InMemorySink,
 *   EngineConfig with Mode::Detect. Run BOTH phases, merge the findings.
 * - Use a Pest dataset that reads the files, so each file is its own test
 *   and a failure names the file.
 * - Rule of the project: no rule is merged without at least one attack file
 *   and one normal file for it.
 */

it('detects every attack in the corpus')->todo();

it('raises nothing on normal traffic in the corpus')->todo();
