# RAGflow AI provider (aiprovider_ragflow) #

An AI provider for Moodle's core AI subsystem that connects Moodle's AI actions to a
[RAGflow](https://ragflow.io/) instance. Answers are produced by a RAGflow **chat assistant**, so they are
grounded in the assistant's retrieval-augmented knowledge base rather than a plain language model, and the
source documents behind an answer can be appended as clickable links. It is the **shared backend of the
RAGflow Moodle suite**: the Helpdesk, Tutor and Search plugins build on its chat and search engine, so the
RAGflow credentials and knowledge bases are configured once and reused across the suite.

## Features ##

* Serves the core AI actions **Generate text**, **Summarise text** and **Explain text** from a RAGflow
  chat assistant.
* The model comes from the chosen assistant automatically — there is no error-prone model field to fill in.
* The chat assistant is picked from a live list of your RAGflow assistants.
* **Sources / citations** (optional): the documents behind an answer are appended as a numbered, linked list.
* **Document source** options: *This Moodle* (optionally scoped to a course or the user's courses),
  *External Moodle* (only documents shared across sites), or the *whole knowledge base* (no filter).
* **Secure file proxy**: where a feature enables it, source files are served through Moodle behind login and
  a signed, time-limited, per-user link, so the RAGflow API key never reaches the browser.
* **Shared chat engine** for the RAGflow features (the Helpdesk drawer, the course Tutor and the Search
  block), including optional **conversation memory** and **long-term memory** built on RAGflow's native
  Memory.
* A **per-user rate limit** for the chat engine; the core AI actions also respect Moodle's own rate limits.
* Stores **no personal data by default**.

## Requirements ##

* **Moodle 5.0–5.2** (uses the core AI subsystem; Moodle 4.5 and earlier are not supported).
* **External service (RAGflow), version 0.25 or later:** a reachable [RAGflow](https://ragflow.io/) instance
  with at least one **chat assistant** and a **RAGflow API key**. RAGflow can be **self-hosted or hosted by
  RAGcon**. This provider is the backend the other suite plugins depend on; without a configured RAGflow
  tenant it cannot answer.

## Installation ##

1. Copy the plugin to `ai/provider/ragflow` in the Moodle tree (**Moodle 5.1+**: `public/ai/provider/ragflow`).
2. Complete the installation via *Site administration → Notifications* or `php admin/cli/upgrade.php`.

## Usage ##

Add a **RAGflow API provider** instance under *Site administration → AI → AI providers* (its API key and
base URL), then configure the AI actions and point Moodle features at the provider. Answers come from the
configured chat assistant; with *Include sources* on, the answer ends with a **Sources** list whose numbers
match the inline citations.

## Documentation ##

Full setup and usage documentation: <https://docs.ragcon.ai/moodle-ragflow/plugins/provider/>

## Privacy and GDPR ##

* Implements the **Moodle Privacy API**. By default the plugin stores **no personal data**; prompts submitted
  through the core AI subsystem are sent to the configured RAGflow service for processing.
* With a feature's **conversation memory** or **long-term memory** enabled, per-user RAGflow session
  references — and, for long-term memory, durable per-user facts in RAGflow's Memory — are kept so a
  conversation can continue and be recalled. These are pruned on a schedule and removed on user deletion and
  through Moodle's privacy (GDPR) delete. Long-term memory is **opt-in** and each user can turn it off for
  themselves. RAGflow is a third-party processor and can be **self-hosted or hosted by RAGcon**, so the
  processing location is under the operator's control.

## Issues & Contributing ##

* Issues and feature requests: <https://github.com/ragcon-ai/moodle-aiprovider_ragflow/issues>

  Please include your **RAGflow version**, **Moodle version**, **plugin version** and the **exact steps to
  reproduce**.
* Pull requests are welcome. The plugin stays **GPLv3**; by contributing you agree your changes are licensed
  under the same terms.

## Support ##

Professional support and web hosting for RAGflow + Moodle are available from **RAGcon GmbH** —
<https://www.ragcon.ai/en> (www.ragcon.ai).

## Community ##

* Moodle — <https://moodle.org>
* RAGflow — <https://ragflow.io>

## Changelog ##

### 0.7.0 ###

* **First public release (beta).** The shared RAGflow backend for Moodle's core AI subsystem: grounded,
  retrieval-augmented answers from a RAGflow chat assistant with optional sources and citations, a secure
  file proxy, per-course and whole-knowledge-base document scoping, and the shared chat engine —
  conversation memory and long-term memory — that powers the RAGflow Helpdesk, Tutor and Search plugins.

## Acknowledgements ##

This plugin integrates two independent software projects:

* **Moodle** — software by Moodle Pty Ltd, released under the GNU GPL v3 or later
  (<https://github.com/moodle/moodle>). *The word Moodle and associated Moodle logos are trademarks or
  registered trademarks of Moodle Pty Ltd or its related affiliates.*
* **RAGflow** — open-source software by InfiniFlow Inc., released under the Apache License 2.0
  (<https://ragflow.io> · <https://github.com/infiniflow/ragflow>).

This plugin is an independent integration and is not affiliated with or endorsed by Moodle Pty Ltd or
InfiniFlow Inc.

## Development ##

This plugin is part of the Moodle RAGflow suite, developed with the help of a range of AI tools under the
professional supervision of the RAGcon GmbH team — pairing fast, AI-assisted development with human review,
automated testing and security checks before every release.

## License ##

Copyright 2026 RAGcon GmbH <info@ragcon.ai>

This program is free software: you can redistribute it and/or modify it under the terms of the GNU
General Public License as published by the Free Software Foundation, either version 3 of the License,
or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even
the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General
Public License for more details.

The full licence text is in `LICENSE`, or at <https://www.gnu.org/licenses/gpl-3.0.html>.
