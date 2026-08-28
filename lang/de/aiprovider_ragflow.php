<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * German language strings for aiprovider_ragflow.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action:explain_text:chatid'] = 'RAGflow Chat-Assistant';
$string['action:explain_text:chatid_help'] = 'Der zu verwendende RAGflow-Chat-Assistant. Es werden dessen eigenes Modell und Wissensbasis(en) genutzt (RAGflow ignoriert ein im Request gesendetes Modell). Wähle einen Assistant mit Wissensbasis für RAG-gestützte Antworten oder einen ohne Wissensbasis, um RAGflow als reinen LLM-Proxy zu nutzen. Die Optionen zu Dokumentquelle und Quellenangaben unten wirken nur, wenn der Assistant eine Wissensbasis hat.';
$string['action:explain_text:systeminstruction'] = 'System-Anweisung';
$string['action:explain_text:systeminstruction_help'] = 'Anweisungen, die als System-Nachricht vorangestellt werden, um die Antwort zu steuern.';
$string['action:generate_text:chatid'] = 'RAGflow Chat-Assistant';
$string['action:generate_text:chatid_help'] = 'Der zu verwendende RAGflow-Chat-Assistant. Es werden dessen eigenes Modell und Wissensbasis(en) genutzt (RAGflow ignoriert ein im Request gesendetes Modell). Wähle einen Assistant mit Wissensbasis für RAG-gestützte Antworten oder einen ohne Wissensbasis, um RAGflow als reinen LLM-Proxy zu nutzen. Die Optionen zu Dokumentquelle und Quellenangaben unten wirken nur, wenn der Assistant eine Wissensbasis hat.';
$string['action:generate_text:systeminstruction'] = 'System-Anweisung';
$string['action:generate_text:systeminstruction_help'] = 'Anweisungen, die als System-Nachricht vorangestellt werden, um die Antwort zu steuern.';
$string['action:summarise_text:chatid'] = 'RAGflow Chat-Assistant';
$string['action:summarise_text:chatid_help'] = 'Der zu verwendende RAGflow-Chat-Assistant. Es werden dessen eigenes Modell und Wissensbasis(en) genutzt (RAGflow ignoriert ein im Request gesendetes Modell). Wähle einen Assistant mit Wissensbasis für RAG-gestützte Antworten oder einen ohne Wissensbasis, um RAGflow als reinen LLM-Proxy zu nutzen. Die Optionen zu Dokumentquelle und Quellenangaben unten wirken nur, wenn der Assistant eine Wissensbasis hat.';
$string['action:summarise_text:systeminstruction'] = 'System-Anweisung';
$string['action:summarise_text:systeminstruction_help'] = 'Anweisungen, die als System-Nachricht vorangestellt werden, um die Antwort zu steuern.';
$string['actionhint'] = 'Antworten sind Retrieval-Augmented: Sie stammen aus dem gewählten RAGflow-Chat-Assistant und werden aus dessen Wissensbasis erzeugt. Es wird das eigene Modell des Assistants verwendet (ein angefordertes Modell wird ignoriert).';
$string['apikey'] = 'RAGflow API-Schlüssel';
$string['apikey_help'] = 'Der RAGflow API-Schlüssel. In RAGflow unter Benutzereinstellungen → API (Pfad /user-setting/api) erzeugen. Er wird als Bearer-Token an den OpenAI-kompatiblen Endpoint gesendet und zum Auflisten der verfügbaren Chat-Assistants verwendet.';
$string['baseurl'] = 'RAGflow Basis-URL';
$string['baseurl_help'] = 'Die Basis-URL Ihrer RAGflow-Instanz, z. B. https://ragflow.example.com.';
$string['chatforgetconfirm'] = 'Alles löschen, was sich der Assistent über dich gemerkt hat? Das kann nicht rückgängig gemacht werden.';
$string['chatforgetdone'] = 'Deine gemerkten Informationen wurden gelöscht.';
$string['chatforgetmemory'] = 'Alle Erinnerungen über mich löschen';
$string['chatforgetmemory_help'] = 'Löscht dauerhaft alle Fakten, die sich die KI über dich gemerkt hat, und beendet das aktuelle Gespräch. Dies kann nicht rückgängig gemacht werden.';
$string['chatkblabel'] = '{$a->name} — {$a->count} Wissensbasis(en)';
$string['chatnewconversation'] = 'Neues Gespräch';
$string['chatnewconversation_help'] = 'Die KI wird sich Fakten aus dem Gespräch merken, damit diese auch in kommenden Gesprächen wieder zur Verfügung stehen.';
$string['chatnewprivate'] = 'Neues privates Gespräch';
$string['chatnewprivate_help'] = 'Es werden keine Daten aus alten Erinnerungen herbeigezogen oder neue gespeichert.';
$string['chatnokblabel'] = '{$a} — ohne Wissensbasis (nur LLM-Proxy)';
$string['chatplaceholder'] = 'Stelle eine Frage…';
$string['chatrestoring'] = 'Stelle unser letztes Gespräch wieder her…';
$string['chatsend'] = 'Senden';
$string['coursemetadatafield'] = 'Kurs-Metadatenfeld';
$string['coursemetadatafield_help'] = 'Das RAGflow-Metadatenfeld, das die Moodle-Kurs-ID enthält. Standard: course_id.';
$string['coursescope'] = 'Auf Kurs(e) einschränken';
$string['coursescope:current'] = 'Aktueller Kurs';
$string['coursescope:off'] = 'Keine Einschränkung';
$string['coursescope:usercourses'] = 'Kurse der Nutzerin/des Nutzers';
$string['coursescope_help'] = 'Schränkt die RAGflow-Wissensbasis auf Dokumente ein, deren Metadatenfeld (unten) zu einer Moodle-Kurs-ID passt (als extra_body metadata_condition gesendet). Nur verfügbar, wenn die Dokumente aus diesem Moodle stammen (Quelle „Dieses Moodle") – Kurs-IDs sind nur innerhalb dieser Site eindeutig und passen daher nie zu Dokumenten aus einem anderen Moodle. Erfordert, dass Ihre Dokumente diese Metadaten tragen; trifft kein Kurs zu, wird keine Einschränkung gesendet.';
$string['createkb:emptyname'] = 'Bitte einen Namen für die neue Wissensbasis eingeben.';
$string['createkb:nameexists'] = 'Eine Wissensbasis oder ein Assistent mit dem Namen „{$a}" existiert bereits. Bitte einen anderen Namen wählen.';
$string['datasource'] = 'Dokumentquelle';
$string['datasource:external'] = 'Externes Moodle über Moodle-Connector';
$string['datasource:locked'] = 'Die Dokumentenquelle wird bei der Anlage des Blocks festgelegt und kann danach nicht mehr geändert werden. Um eine andere Quelle zu verwenden, den Block entfernen und einen neuen anlegen.';
$string['datasource:summary:external'] = 'Mit einer Wissensbasis verbunden; nur ausdrücklich freigegebene Dokumente werden genutzt, über den Moodle-Connector.';
$string['datasource:summary:thiscourse'] = 'Dateien werden aus diesem Block verwaltet (eigene Wissensbasis; kein Metadaten-Filter).';
$string['datasource:summary:thismoodle'] = 'Mit einer Wissensbasis verbunden; über den Moodle-Connector auf diesen Kurs gefiltert.';
$string['datasource:summary:wholekb'] = 'Mit einer Wissensbasis verbunden; kein Metadaten-Filter (die ganze Wissensbasis wird durchsucht).';
$string['datasource:thiscourse'] = 'Diese Blockinstanz';
$string['datasource:thismoodle'] = 'Dieses Moodle über Moodle-Connector';
$string['datasource:wholekb'] = 'RAGflow Wissensbasis';
$string['datasource_help'] = 'Woher die Dokumente der Wissensbasis stammen — das bestimmt, welcher Metadaten-Filter (falls überhaupt) bei jeder Suche angewendet wird:

* **Diese Blockinstanz** — eine in Moodle aus diesem Block verwaltete Wissensbasis. Ihre Dokumente werden über den Block hinzugefügt/entfernt und die gesamte Wissensbasis wird genutzt (sie *ist* der Scope, also **kein Metadaten-Filter**). Nur diese Quelle bietet die Dateiverwaltung.
* **RAGflow Wissensbasis** — **kein Metadaten-Filter**; die komplette Wissensbasis des Assistenten wird durchsucht. Nutze dies für eine Wissensbasis, die nicht aus Moodle befüllt wurde (deren Dokumente keine \'course_id\'-/\'external_sharing\'-Metadaten haben) — sonst werden alle Dokumente herausgefiltert und Antworten kommen leer zurück.
* **Dieses Moodle über Moodle-Connector** — eine geteilte Site-Wissensbasis, die über RAGflows integrierten **Moodle-Connector** befüllt wird. Antworten werden per Dokument-Metadaten (\'course_id\' + Site-URL) **auf den aktuellen Kurs eingeschränkt**. **Setzt den Connector voraus** — ohne ihn trägt kein Dokument diese Metadaten, und der Tutor antwortet immer „nichts gefunden".
* **Externes Moodle über Moodle-Connector** — Dokumente aus einem *anderen* Moodle, über den Connector importiert. Es werden nur ausdrücklich freigegebene Dokumente (Metadatum \'external_sharing = 1\') verwendet; es gibt **kein Kurs-Scoping**, und jede Quelle verlinkt auf ihr eigenes Moodle. **Setzt den Connector voraus.**

Wie eine Quelldatei geöffnet wird — Aktivitätslink oder sicherer RAGflow-Proxy — steuert die separate Option „Dateien über RAGflow-Proxy bereitstellen".';
$string['error:downloaddenied'] = 'Sie sind nicht berechtigt, dieses Dokument hier herunterzuladen.';
$string['error:kbmissing'] = 'Diese Suche ist derzeit nicht verfügbar. Bitte die Website-Administration bitten, ihre Wissensbasis neu zu verbinden.';
$string['error:kbmissing_detail'] = 'Die konfigurierte RAGflow-Wissensbasis existiert nicht mehr (id {$a}). Bitte in den Block-Einstellungen eine gültige Wissensbasis auswählen.';
$string['error:nochatid'] = 'Für diese Aktion ist kein RAGflow-Chat-Assistant konfiguriert. Legen Sie ihn in den Aktionseinstellungen des Providers fest.';
$string['error:notconfigured'] = 'Der RAGflow-Provider ist nicht vollständig konfiguriert.';
$string['error:ratelimited'] = 'Zu viele Anfragen. Bitte kurz warten und erneut versuchen.';
$string['error:referencemissing'] = 'Dieser Assistent ist derzeit nicht verfügbar. Bitte die Website-Administration bitten, ihn neu zu verbinden.';
$string['error:referencemissing_detail'] = 'Der konfigurierte RAGflow-Assistent existiert nicht mehr (id {$a}). Anfragen darauf schlagen fehl — bitte in den Einstellungen einen gültigen Assistenten auswählen.';
$string['error:seedpending'] = 'Der Seed der Wissensbasis ist noch nicht geparst; wird erneut versucht.';
$string['error:tokenexpired'] = 'Dieser Download-Link ist abgelaufen. Führen Sie die Aktion erneut aus, um einen neuen zu erhalten.';
$string['error:tokeninvalid'] = 'Ungültiger oder nicht autorisierter Download-Link.';
$string['error:unexpectedresponse'] = 'Unerwartete Antwort von RAGflow.';
$string['errordetails'] = 'Details';
$string['event:chatcompleted'] = 'RAGflow-Chat abgeschlossen';
$string['event:chatfailed'] = 'RAGflow-Chat fehlgeschlagen';
$string['event:searchperformed'] = 'RAGflow-Suche ausgeführt';
$string['extraparams'] = 'Zusätzliche Parameter (JSON)';
$string['extraparams_help'] = 'Optionales JSON-Objekt, das in den Request-Body an RAGflow eingefügt wird. Nutzen Sie extra_body für RAGflow-spezifische Optionen, z. B. {"extra_body": {"reference": true}} für Quellenangaben. Hinweis: Modell und Generierungseinstellungen (Temperatur usw.) bestimmt der Chat-Assistant – reine Sampling-Parameter werden ggf. ignoriert.';
$string['helpdeskchatid'] = 'Helpdesk-Chat-Assistant';
$string['helpdeskchatid_help'] = 'Optional. Ein separater RAGflow-Chat-Assistant für Anfragen außerhalb eines echten Kurses (Startseite bzw. – falls aktiviert – site-weit) – z. B. eine organisationsweite Helpdesk-Wissensbasis. Leer lassen, um überall den Assistant von oben zu verwenden. In diesem Modus wird kein Kurs-Scope angewendet.';
$string['helpdesklongtermmemory'] = 'Helpdesk-Langzeitgedächtnis';
$string['helpdesklongtermmemory_help'] = 'Zusätzlich zum Merken eines einzelnen Gesprächs werden dauerhafte Fakten über die Nutzerin/den Nutzer (Name, Rolle, Sprache, Präferenzen, wiederkehrende Ziele) über Gespräche hinweg mitgeführt – so kennt auch ein neues Gespräch die Person. Dies nutzt RAGflows natives Memory: nach jeder Antwort wird der Turn gespeichert (RAGflow extrahiert die Fakten selbst) und relevante Erinnerungen werden in ein neues Gespräch eingespielt. Erfordert „Helpdesk-Gesprächsgedächtnis" sowie die RAGflow-Memory-ID und Agent-ID unten. Hinweis: Dabei werden mehr personenbezogene Daten in RAGflow gespeichert (siehe Datenschutzhinweis); sie werden mit den Nutzerdaten und bei Kontolöschung entfernt.';
$string['helpdeskmemory'] = 'Helpdesk-Gesprächsgedächtnis';
$string['helpdeskmemory_help'] = 'Merkt sich den Gesprächsverlauf über Turns und Seiten-Reloads hinweg per RAGflow-Session. Gilt nur für den Helpdesk (Site-/Systemkontext). Hinweis: Aktivieren speichert die Unterhaltung server-seitig in RAGflow (siehe Datenschutzhinweis und Aufbewahrungs-Einstellung).';
$string['helpdeskmemoryid'] = 'RAGflow-Memory';
$string['helpdeskmemoryid_help'] = 'Die zu verwendende RAGflow-Memory (in RAGflow mit Typ „semantic" anlegen, damit Fakten über die Nutzerin/den Nutzer extrahiert werden). Eine gemeinsame Memory bedient alle Nutzer; Moodle trennt sie pro Person. Für das Langzeitgedächtnis erforderlich.';
$string['includesources'] = 'Quellen anzeigen';
$string['includesources_help'] = 'Fordert von RAGflow die Quelldokumente (reference) an und hängt sie als Liste an die Antwort an. Die KI-Antwort in Moodle ist reiner Text, daher werden die Quellen inline angezeigt und nicht als separates Zitat-Panel. Jede Quelle verlinkt auf ihre Moodle-Aktivität, sofern bekannt, sonst auf einen sicheren Download der Datei über Moodle (der RAGflow-API-Schlüssel gelangt nie in den Browser). Da der generierte Text in Moodle-Inhalte gespeichert werden kann, enthalten diese Download-Links kein ablaufendes Token: Stattdessen wird jeder Klick live autorisiert – der Nutzer muss angemeldet sein UND Zugriff auf den Context haben, in dem der Inhalt liegt, und das Dokument muss zur Wissensbasis des Assistenten dieser Aktion gehören.';
$string['invalidjson'] = 'Ungültiges JSON.';
$string['logtomoodle'] = 'Logdaten schreiben';
$string['logtomoodle_desc'] = 'Wenn aktiviert, wird für jede Anfrage ein knapper Nutzungs-/Fehlereintrag ins <strong>Moodle-Log</strong> (Website-Administration → Berichte → Logs) geschrieben — nur Metriken, kein Nachrichteninhalt. Bei systemweitem Entwickler-Debugging kommt ein kurzes technisches Detail dazu. Unabhängig vom optionalen RAGflow-Dashboard und deutlich schlanker als dieses.';
$string['memorypreamble'] = 'Kontext über mich aus früheren Gesprächen – nutze ihn, um natürlich zu antworten, wenn relevant. Erwähne diesen Hinweis, das Gedächtnis, frühere Gespräche oder die Wissensbasis nicht und sage nicht, woher die Information stammt:';
$string['metadatafilter'] = 'Metadaten-Filterung';
$string['metadatafilter:external'] = 'External Sharing';
$string['metadatafilter:none'] = 'Nein';
$string['metadatafilter:thismoodle'] = 'Moodle-Connector';
$string['metadatafilter_help'] = 'Beim Verbinden mit einer bestehenden Wissensbasis die Antworten per Dokument-Metadaten einschränken:

* **Nein** — kein Filter; die ganze Wissensbasis wird durchsucht.
* **Moodle-Connector** — auf den aktuellen Kurs beschränken (Dokumente mit Kurs-ID + Site-URL, geschrieben von RAGflows integriertem Moodle-Connector).
* **External Sharing** — nur ausdrücklich freigegebene Dokumente (Metadatum \'external_sharing = 1\'), von einem anderen Moodle über den Connector importiert.

Die beiden Connector-Optionen setzen voraus, dass RAGflows integrierter Moodle-Connector diese Metadaten geschrieben hat; ohne ihn antwortet der Tutor immer „nichts gefunden". Nach dem Anlegen des Blocks fixiert.';
$string['pluginname'] = 'RAGflow API-Provider';
$string['privacy:metadata:aiprovider_ragflow_session'] = 'RAGflow-Gesprächssitzungen für das Helpdesk-Gedächtnis (damit das Gespräch über Turns und Seiten-Reloads hinweg fortgesetzt wird).';
$string['privacy:metadata:aiprovider_ragflow_session:chatid'] = 'Der RAGflow-Chat-Assistant, zu dem die Sitzung gehört.';
$string['privacy:metadata:aiprovider_ragflow_session:sessionid'] = 'Die RAGflow-Sitzungskennung, die auf das gespeicherte Gespräch verweist.';
$string['privacy:metadata:aiprovider_ragflow_session:timecreated'] = 'Zeitpunkt der Erstellung der Sitzung.';
$string['privacy:metadata:aiprovider_ragflow_session:userid'] = 'Die Nutzerin/der Nutzer, zu der/dem die Gesprächssitzung gehört.';
$string['privacy:metadata:preference:privatemode'] = 'Ob die Nutzerin/der Nutzer den privaten (Inkognito-)Modus für den Helpdesk-Chat aktiviert hat, sodass nichts im Langzeitgedächtnis gespeichert oder daraus abgerufen wird.';
$string['privacy:metadata:ragflow'] = 'Anfragen (und bei aktiviertem Gedächtnis das laufende Gespräch sowie erinnerte Fakten) werden an den konfigurierten RAGflow-Dienst gesendet und dort gespeichert.';
$string['privacy:metadata:ragflow:memory'] = 'Bei aktiviertem Langzeitgedächtnis werden Gesprächs-Turns in RAGflows Memory (pro Nutzer) gespeichert, damit dauerhafte Fakten in späteren Gesprächen erinnert werden können.';
$string['privacy:metadata:ragflow:prompt'] = 'Die an RAGflow gesendete Frage/Anfrage.';
$string['ragflow:viewerrordetails'] = 'Technische Ursache sehen, wenn eine Chat-Anfrage fehlschlägt';
$string['reference:notice_missing'] = 'Die konfigurierte Referenz existiert in RAGflow nicht mehr. Anfragen, die sie nutzen, schlagen fehl — bitte eine andere wählen. Der gespeicherte Wert bleibt erhalten, bis du ihn änderst.';
$string['reference:notice_unverified'] = 'RAGflow war nicht erreichbar, um diese Referenz zu prüfen. Deine gespeicherte Konfiguration ist unverändert — das ist ein Verbindungsproblem, kein Konfigurationsproblem.';
$string['reference:option_missing'] = 'Nicht verfügbar — nicht mehr in RAGflow ({$a})';
$string['reference:option_unverified'] = 'Aktuell — konnte nicht geprüft werden ({$a})';
$string['searchbutton'] = 'Suchen';
$string['searchexcerpt'] = 'Auszug anzeigen';
$string['searching'] = 'Suche läuft …';
$string['searchnoresults'] = 'Keine passenden Dokumente gefunden.';
$string['searchplaceholder'] = 'Wissensbasis durchsuchen …';
$string['searchscore'] = 'Übereinstimmung';
$string['serveviaproxy'] = 'Dateien über RAGflow-Proxy bereitstellen';
$string['serveviaproxy_help'] = 'Wenn aktiviert, streamt jeder Quell-Link die zugrunde liegende Datei aus RAGflow über einen sicheren, signierten, zeitlich begrenzten Moodle-Proxy (download.php), statt auf eine Moodle-Aktivität zu verlinken. Der RAGflow-API-Schlüssel gelangt nie in den Browser. Unabhängig von der Dokumentquelle; nur relevant, wenn „Quellen anzeigen" aktiv ist. Voraussetzung: Die zitierten Dokumente müssen als echte Dateien in einem RAGflow-Dataset vorliegen, sodass die Quellenangabe die RAGflow-eigenen Metadatenfelder dataset_id und document_id enthält — der Proxy nutzt diese zum Abruf der Datei. Quellen ohne diese Felder werden ohne Link angezeigt.';
$string['sourcesheading'] = 'Quellen:';
$string['task:prunesessions'] = 'Veraltete RAGflow-Gesprächssitzungen bereinigen';
$string['tokenttl'] = 'Gültigkeit des Download-Links (Sekunden)';
$string['tokenttl_help'] = 'Wie lange ein signierter Quell-/Datei-Download-Link gültig bleibt, in Sekunden (Standard 60; Minimum 15). Download-Links werden erst im Moment des Klicks auf eine Quelle oder Datei erzeugt – nicht beim Rendern des Panels/der Antwort. Eine kurze Gültigkeit ist daher unproblematisch: Sie muss nur den Download selbst abdecken. Ein kleinerer Wert verkleinert das Zeitfenster, falls ein Link nach außen gelangt. Setze den Wert bequem über die Zeit, die ein einzelner Download dauert.';
