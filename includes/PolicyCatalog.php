<?php
declare(strict_types=1);

/**
 * Original BanglaVerseWiki governance text. These policies follow established
 * open-encyclopedia principles but are written for this platform and are not a
 * copy of another site's policy pages.
 */
function policy_catalog(): array
{
    return [
        'founding-principles' => [
            'title' => 'Founding principles', 'category' => 'Core content',
            'summary' => 'The non-negotiable principles behind a free, neutral, verifiable, respectful, and openly licensed encyclopedia.',
            'sections' => [
                'An encyclopedia first' => ['Articles summarize significant knowledge; they are not advertising, personal blogs, social feeds, directories, or a place to publish original discoveries.', 'Readers should be able to distinguish encyclopedic content from discussion, administration, and drafts.'],
                'Neutral and verifiable' => ['Represent significant published viewpoints in proportion to reliable coverage.', 'Claims that may reasonably be challenged need citations readers can inspect.'],
                'Open knowledge' => ['Contributors must submit only material they can license to the project.', 'Attribution and license information must travel with imported text and media.'],
                'Respectful collaboration' => ['Discuss content, not contributors. Seek consensus, explain changes, and assume good faith unless evidence shows otherwise.'],
                'Improve the rules responsibly' => ['Policies protect the encyclopedia and its community. Administrators may interpret them, but no role may ignore security, law, privacy, or attribution requirements.'],
            ],
        ],
        'neutral-point-of-view' => [
            'title' => 'Neutral point of view', 'category' => 'Core content',
            'summary' => 'Write fairly, without advocacy, and in proportion to high-quality published coverage.',
            'sections' => [
                'Required standard' => ['State facts in an impartial voice. Attribute opinions to their published sources instead of presenting them as the platform’s view.', 'Do not create false balance: fringe claims should not receive the same weight as a well-established expert consensus.'],
                'Disputed topics' => ['Describe the nature of a dispute, the strongest reliable evidence, and who holds each significant position.', 'Avoid loaded labels, editorial adjectives, speculation, and conclusions that sources do not make.'],
                'How to fix bias' => ['Add missing sourced perspectives, improve attribution, and rewrite promotional or hostile wording.', 'Use the talk page when a neutral formulation is contested.'],
            ],
        ],
        'verifiability' => [
            'title' => 'Verifiability and citations', 'category' => 'Core content',
            'summary' => 'Readers must be able to check important claims against reliable, published sources.',
            'sections' => [
                'What needs a citation' => ['Cite quotations, statistics, contentious claims, medical or legal statements, and material about living people.', 'A citation must support the nearby claim; a merely related link is not enough.'],
                'Citation quality' => ['Prefer the original publication or a reputable secondary source with editorial oversight.', 'Give enough bibliographic detail to identify the work even if a URL later changes.'],
                'Unsourced material' => ['Editors may challenge or remove unsupported claims. Harmful unsourced claims about living people should be removed immediately.', 'A large reference list does not compensate for sources that do not verify the text.'],
            ],
        ],
        'reliable-sources' => [
            'title' => 'Reliable sources', 'category' => 'Core content',
            'summary' => 'Evaluate sources by editorial control, subject expertise, independence, reputation, and context.',
            'sections' => [
                'Usually strong' => ['Peer-reviewed scholarship, reputable books, official statistical publications, and established news organizations are often appropriate.', 'Use primary sources carefully and only for straightforward statements they directly establish.'],
                'Usually weak' => ['Anonymous posts, scraped content, press-release copies, user-generated databases, and self-published claims normally do not establish notability or contested facts.', 'A source may be reliable for one topic and unsuitable for another.'],
                'Independence' => ['Sources controlled by a subject can verify uncontroversial facts about itself, but not independent significance or praise.', 'Sponsored material must be identified and given little weight.'],
            ],
        ],
        'no-original-research' => [
            'title' => 'No original research', 'category' => 'Core content',
            'summary' => 'Articles summarize published knowledge; they do not introduce unpublished facts, theories, or analysis.',
            'sections' => [
                'Not allowed' => ['Do not publish personal investigations, unpublished survey results, new translations used to make novel claims, or conclusions assembled from sources that do not state them.', 'Do not combine facts into an implication that no reliable source has made.'],
                'Allowed synthesis' => ['Routine summaries and simple calculations may be acceptable when the method is obvious, accurately cited, and does not advance a new argument.', 'When interpretation matters, attribute it to a reliable source.'],
            ],
        ],
        'notability' => [
            'title' => 'Article notability', 'category' => 'Content decisions',
            'summary' => 'A standalone article needs substantial coverage in multiple reliable sources independent of its subject.',
            'sections' => [
                'General test' => ['Coverage should discuss the subject in meaningful depth, not merely name it in a list, database entry, routine announcement, or passing sentence.', 'Notability is based on existing coverage, not popularity, importance to one group, or expected future fame.'],
                'Alternatives' => ['Non-notable material may be merged into a broader article when it is relevant and verifiable.', 'Directories, résumés, product catalogs, and promotional profiles are not encyclopedia articles.'],
                'Discussion' => ['Borderline cases should be discussed with sources. Deletion is not a vote; policy-based evidence carries more weight than head counts.'],
            ],
        ],
        'living-people' => [
            'title' => 'Biographies of living people', 'category' => 'Safety and legal',
            'summary' => 'Material about living people requires exceptional care, high-quality sourcing, privacy awareness, and neutral wording.',
            'sections' => [
                'Immediate standard' => ['Remove unsourced or poorly sourced contentious material immediately from articles, drafts, captions, and discussions.', 'Do not repeat rumors, private addresses, personal contact details, or unnecessary sensitive information.'],
                'Public relevance' => ['Include private or sensitive facts only when high-quality sources establish clear encyclopedic relevance.', 'A person’s own published statement may verify their statement, but it does not automatically prove the underlying claim.'],
                'Escalation' => ['Report legal, safety, harassment, or privacy concerns privately to administrators rather than amplifying them on public pages.'],
            ],
        ],
        'copyright-licensing' => [
            'title' => 'Copyright, licensing, and attribution', 'category' => 'Safety and legal',
            'summary' => 'Contribute original or compatibly licensed material and preserve attribution for every import.',
            'sections' => [
                'Text' => ['Do not paste copyrighted prose merely because it is online. Paraphrase facts in your own words and cite the source.', 'Licensed encyclopedia imports must retain the source URL, license, and revision history required by that license.'],
                'Images' => ['Upload only media you created, that is in the public domain, or whose license permits reuse.', 'Remote media must pass through the platform’s ImgBB pipeline; hotlinking does not replace permission or attribution.'],
                'Violations' => ['Administrators may remove infringing content and revisions without notice. Repeated infringement can result in blocking.', 'Contact the administrator with a precise URL and ownership evidence for a takedown request.'],
            ],
        ],
        'plagiarism' => [
            'title' => 'Plagiarism and close paraphrasing', 'category' => 'Safety and legal',
            'summary' => 'Credit ideas and quotations, and do not disguise copied expression with superficial word changes.',
            'sections' => [
                'Avoiding plagiarism' => ['Read several sources, understand the subject, write independently, and cite the sources that support each claim.', 'Use quotation marks for brief exact wording and include a precise citation.'],
                'Remediation' => ['Rewrite copied passages, restore required attribution, and request revision suppression when copyrighted text must be removed from history.'],
            ],
        ],
        'civility' => [
            'title' => 'Civility and good-faith collaboration', 'category' => 'Community conduct',
            'summary' => 'Be direct about content problems while treating contributors with patience and dignity.',
            'sections' => [
                'Expected conduct' => ['Explain edits, answer reasonable questions, avoid sarcasm and personal attacks, and give newcomers room to learn.', 'Criticize evidence and wording—not intelligence, identity, motives, or character.'],
                'When discussion fails' => ['Pause, summarize areas of agreement, request a neutral third opinion, or ask a moderator to facilitate.', 'Do not edit-war. Repeatedly reverting without discussion may lead to protection or blocking.'],
            ],
        ],
        'harassment' => [
            'title' => 'Harassment and personal information', 'category' => 'Community conduct',
            'summary' => 'Harassment, threats, stalking, outing, intimidation, and discriminatory abuse are prohibited.',
            'sections' => [
                'Prohibited behavior' => ['Do not publish private information, coordinate off-site abuse, make threats, or target contributors across pages.', 'Sexual harassment, hate speech, and repeated unwanted contact are serious violations.'],
                'Reporting' => ['Preserve evidence and report privately when public reporting would expose more personal information.', 'Administrators may block accounts, hide revisions, protect pages, and contact hosting or legal authorities where necessary.'],
            ],
        ],
        'conflict-of-interest' => [
            'title' => 'Conflict of interest', 'category' => 'Community conduct',
            'summary' => 'Avoid editing where personal, financial, political, or organizational ties could compromise independent judgment.',
            'sections' => [
                'Disclosure' => ['Disclose relevant relationships and propose substantial changes on the talk page.', 'Do not create promotional biographies, company profiles, campaign pages, or reputation-management content.'],
                'Paid work' => ['Paid contributors must disclose the employer, client, and affiliation. Disclosure does not make promotional editing acceptable.', 'Independent editors decide article content through sources and consensus.'],
            ],
        ],
        'consensus' => [
            'title' => 'Consensus and discussion', 'category' => 'Community process',
            'summary' => 'Editorial decisions are made by reasoned agreement grounded in evidence and policy, not ownership or simple voting.',
            'sections' => [
                'Building consensus' => ['Start with focused proposals, cite sources, identify policy considerations, and listen for workable compromises.', 'Silence may indicate provisional acceptance, but major or contentious changes deserve clear notice.'],
                'Changing consensus' => ['Consensus can change when new sources, arguments, or community experience emerge.', 'Canvassing allies, forum shopping, and repeated reopening without new evidence are disruptive.'],
            ],
        ],
        'dispute-resolution' => [
            'title' => 'Dispute resolution', 'category' => 'Community process',
            'summary' => 'Resolve disagreements at the lowest effective level with calm discussion, evidence, and uninvolved help.',
            'sections' => [
                'Steps' => ['Discuss the exact content issue on the talk page.', 'Seek a third opinion or moderator facilitation.', 'Use a broader community discussion for disputes affecting many pages or policies.', 'Ask administrators to address conduct or enforcement, not to choose a preferred viewpoint.'],
                'Urgent cases' => ['Vandalism, threats, privacy exposure, copyright violations, and harmful claims about living people may be reverted or reported immediately.'],
            ],
        ],
        'page-protection' => [
            'title' => 'Page protection', 'category' => 'Administration',
            'summary' => 'Administrators may lock a page when open editing would create a serious, continuing risk.',
            'sections' => [
                'Appropriate uses' => ['Persistent vandalism, edit wars, legal or privacy risk, high-risk templates, and emergency incident response may justify protection.', 'Protection is preventive, not an endorsement of the current version.'],
                'Administrator-only protection' => ['A protected BanglaVerseWiki article can be edited or restored only by an administrator.', 'Every lock and unlock records the administrator, reason, duration, and timestamp in the protection log.'],
                'Review' => ['Use the shortest effective duration. Editors may request changes on the talk page or ask an administrator to review the lock.'],
            ],
        ],
        'deletion' => [
            'title' => 'Deletion and archiving', 'category' => 'Administration',
            'summary' => 'Remove or archive pages that cannot responsibly remain, while preserving useful history where possible.',
            'sections' => [
                'Fast action' => ['Obvious spam, attacks, test pages, copyright violations, and dangerous personal information may be removed quickly.', 'Other cases should receive notice and a policy-based discussion.'],
                'Alternatives' => ['Improve, merge, redirect, move to draft, or archive when those actions preserve useful sourced material.', 'Administrators should record reasons and avoid deleting solely because they disagree with a topic.'],
            ],
        ],
        'vandalism' => [
            'title' => 'Vandalism and disruptive editing', 'category' => 'Administration',
            'summary' => 'Deliberate damage is reverted quickly; repeated disruption can lead to blocking and page protection.',
            'sections' => [
                'Vandalism' => ['Blanking pages, inserting hoaxes or abuse, corrupting templates, and repeatedly adding known falsehoods are vandalism.', 'Good-faith mistakes and content disagreements are not vandalism. Explain and correct them normally.'],
                'Response' => ['Revert, warn, document patterns, and escalate repeated abuse. Do not retaliate or engage in edit wars.'],
            ],
        ],
        'bots-automation' => [
            'title' => 'Bots, BRFA, and automated editing', 'category' => 'Administration',
            'summary' => 'Every bot requires public approval, a dedicated account, a narrow task, reliable sourcing, bounded operation, and reversible audit records.',
            'sections' => [
                'Approval before operation' => ['An operator must file a public Bot Request for Approval describing the task, source databases, templates, expected volume, failure handling, and trial evidence. Community members may test, question, and oppose the proposal.', 'Autonomous or mass article creation is prohibited until an administrator closes the BRFA as approved or trial. Material scope changes require a new request or amendment.'],
                'Dedicated identity and API' => ['Approved automation runs through its own bot-role account and a scoped, expiring API token. Human accounts must not disguise automated edits.', 'Tokens are displayed once, stored only as hashes by the platform, and must be revoked after compromise or loss of approval.'],
                'Content safeguards' => ['Every automated claim must be traceable to a reliable cited record. Bots may not invent citations, infer contentious claims, promote subjects, or use a database entry alone where independent coverage is required for notability.', 'Human review is the default, and every trial output requires it. Direct publication begins only after full approval, requires explicit BRFA permission, and may still be disabled by an administrator.'],
                'Limits and non-disruption' => ['Bots must obey daily and batch limits, identifier deduplication, source allowlists, publication controls, and stop-on-error behavior. Operators must pause jobs when objections, upstream corruption, template breakage, or unusual error rates appear.', 'Automation must not overwhelm recent changes, evade protection, edit war, or recreate rejected content.'],
                'Audit and revocation' => ['The platform records requester, bot, BRFA, input provenance, retrieval time and hash, job state, revision, and resulting article. Administrators can pause bots, revoke tokens, cancel jobs, and close an approval.', 'Bot output remains subject to correction, draftification, deletion, attribution, and community review.'],
            ],
        ],
        'authority-data' => [
            'title' => 'Authority data and machine-created articles', 'category' => 'Core content',
            'summary' => 'Structured databases can supply facts, but authority, licensing, notability, context, and provenance must each be evaluated.',
            'sections' => [
                'Approved connectors' => ['Remote records must come from an administrator-registered HTTPS endpoint with an exact pinned host, defined identifier format, known adapter, and recorded license. Arbitrary URLs and silent redirects to unapproved hosts are not authority sources.', 'Connector status may be suspended when an upstream source becomes unreliable, incompatible, or legally unsuitable.'],
                'Defined fields and templates' => ['A bot extracts only mapped fields and fills a pre-approved article and infobox structure. Unrecognized fields, markup, remote scripts, and unsourced prose must not pass through as article content.', 'Retrieval URL, external identifier, source record URL, timestamp, response hash, adapter version, and citations must be retained.'],
                'Authority is not notability' => ['Presence in Wikidata, GBIF, Crossref, OpenAlex, USGS, or another catalog does not automatically justify a standalone article. Connectors marked “review required” can never publish directly, even when a BRFA permits direct operation. The bot and reviewers must apply the relevant notability rule and use independent sources when it requires significant coverage.', 'Database errors must be corrected from reliable evidence and reported upstream when practical.'],
                'Scientific and statistical care' => ['Taxonomy, geographic boundaries, measurements, event data, authorship, and classifications can change. State the applicable date, units, uncertainty, and authority rather than presenting a volatile value as timeless.', 'Sensitive coordinates, personal identifiers, or harmful location data must be omitted or generalized when safety or privacy requires it.'],
            ],
        ],
        'wiki-imports' => [
            'title' => 'Compatible-wiki imports', 'category' => 'Safety and legal',
            'summary' => 'Imports must preserve usable source material and attribution without misrepresenting completeness, review status, or media rights.',
            'sections' => [
                'Compatibility and attribution' => ['Import only from supported encyclopedias with a compatible license. Preserve the canonical source page, source title, revision identifier and timestamp, declared license, import time, and the importing editor.', 'Every imported draft carries a visible import notice. Removing the notice before the attribution obligation is otherwise satisfied is prohibited.'],
                'Content preservation' => ['The importer should retain raw revision wiki source, references, categories, and supported templates as far as the source API and parser permit. Unsupported templates must remain visible for human repair rather than being silently presented as complete prose.', 'Imports enter a reviewable draft or pending workflow; they do not bypass sourcing, neutrality, title, notability, or living-person checks.'],
                'Images' => ['Only files with machine-verifiable reuse terms may be transferred. Each eligible image passes through ImgBB and stores its original description page, author or attribution, license, and hosted URL.', 'Files with unknown, nonfree, fair-use-only, unsupported, oversized, or failed downloads are skipped with a recorded reason. “Import all images” never overrides copyright or service limits.'],
                'Limits and correction' => ['API continuation, image bounds, network failures, parser coverage, and deleted source revisions can affect completeness. Import counts and warnings must be shown so reviewers can compare the draft with its source.', 'If attribution or licensing cannot be repaired, remove the affected text or media.'],
            ],
        ],
        'api-token-security' => [
            'title' => 'API token security', 'category' => 'Safety and legal',
            'summary' => 'Machine credentials are least-privilege, expiring secrets tied to an accountable account and approved purpose.',
            'sections' => [
                'Issuance' => ['Only an administrator may issue a bot API token after approval. Every token has explicit capability scopes, an expiry, a creator, and one dedicated bot account. Authority connector IDs and operator-supplied-payload permission are separately recorded in the current BRFA and rechecked at execution.', 'A token grants no authority beyond its scopes and the current BRFA; approval is rechecked when jobs are queued and executed.'],
                'Storage and transmission' => ['Send tokens only in the Authorization Bearer header over HTTPS. Never place them in URLs, wiki pages, browser code, source control, logs, or screenshots.', 'The platform stores a one-way hash and shows the plaintext secret only once. Operators should use a hosting secret or protected configuration file.'],
                'Revocation and response' => ['Revoke and replace a token immediately after suspected disclosure, operator change, unused access, or withdrawn approval.', 'Rate anomalies, invalid scopes, repeated failures, and use outside approved purpose may trigger suspension and audit.'],
            ],
        ],
        'community-events' => [
            'title' => 'Community events and meetups', 'category' => 'Community process',
            'summary' => 'Events support encyclopedia work while protecting participants, privacy, neutrality, and transparent moderation.',
            'sections' => [
                'Suitable events' => ['Edit-a-thons, training sessions, policy discussions, source workshops, and community meetups must have a clear encyclopedia-related purpose.', 'Event listings are not advertising, fundraising pages, political campaign notices, or endorsements.'],
                'Time and location' => ['Organizers provide a real start and end in an IANA timezone. The platform stores UTC and displays the time locally to readers.', 'Do not publish private home addresses, participant contact details, or sensitive attendance information. Use an online URL or an appropriate public venue.'],
                'Moderation and conduct' => ['New events require moderation before public listing. Administrators may reject, cancel, or edit unsafe, misleading, abandoned, or off-topic listings with a recorded reason.', 'Community conduct, harassment, privacy, copyright, and conflict-of-interest rules apply online and in person.'],
                'RSVPs' => ['Going and interested responses support planning but are not proof of identity or attendance. Participants may change their response.', 'Organizers may not repurpose account or RSVP data for marketing, profiling, or external contact without clear consent.'],
            ],
        ],
        'editing-style' => [
            'title' => 'Editing and style guide', 'category' => 'Editing',
            'summary' => 'Use clear structure, accessible language, useful links, and consistent wiki markup.',
            'sections' => [
                'Article structure' => ['Begin with a concise definition, organize sections logically, and avoid repeating the title or lead.', 'Use informative headings, short paragraphs, and tables only when they improve comparison.'],
                'Language and names' => ['Use the most recognizable neutral title. Mention important alternate Bengali and English names in the lead.', 'Write for a broad audience and explain specialized terms.'],
                'Accessibility' => ['Provide meaningful image alternative text, descriptive link labels, properly nested headings, and readable tables.', 'Do not rely on color alone to communicate meaning.'],
            ],
        ],
        'article-ownership' => [
            'title' => 'No ownership of articles', 'category' => 'Editing',
            'summary' => 'No contributor owns an article; all good-faith editors may improve it within policy.',
            'sections' => [
                'Collaboration' => ['Creating or expanding a page does not grant veto power over later sourced improvements.', 'Editors should still respect ongoing work, explain substantial rewrites, and preserve useful contributions.'],
                'Warning signs' => ['Repeated reverts, instructions that others must ask permission, and personal control of a topic are ownership behavior.'],
            ],
        ],
        'privacy' => [
            'title' => 'Privacy policy', 'category' => 'Legal and platform',
            'summary' => 'BanglaVerseWiki minimizes personal data and uses it only to operate, secure, and improve the encyclopedia.',
            'sections' => [
                'Data collected' => ['Accounts store username, email, password hash, role, and timestamps. Security and activity logs use an irreversible IP hash rather than displaying an address.', 'Search analytics record normalized queries and a session hash to improve results.'],
                'Use and retention' => ['Data supports authentication, abuse prevention, moderation, diagnostics, and aggregate search improvement.', 'Public contributions and revision history are retained for attribution. Administrators should delete unnecessary logs according to hosting and legal needs.'],
                'Third parties' => ['Images are transferred to ImgBB under its terms. IndexNow receives public URLs. Source links take readers to independent websites with their own policies.'],
            ],
        ],
        'terms-of-contribution' => [
            'title' => 'Terms of contribution', 'category' => 'Legal and platform',
            'summary' => 'By contributing, editors confirm they have the right to share the material and allow community revision and reuse.',
            'sections' => [
                'Your responsibility' => ['Submit accurate material in good faith, cite sources, respect copyright and privacy, and do not misuse the platform.', 'Do not upload secrets, malware, illegal content, or material intended to threaten or harass.'],
                'Community editing' => ['Contributions may be edited, moved, merged, attributed, archived, or removed under policy.', 'No guarantee is made that a contribution will remain published or be indexed by search engines.'],
                'Administration' => ['Accounts may be limited or blocked to protect users, data, legal compliance, and encyclopedia integrity.'],
            ],
        ],
        'administrator-accountability' => [
            'title' => 'Administrator accountability', 'category' => 'Administration',
            'summary' => 'Administrative tools exist to protect the project, not to confer editorial ownership or personal authority.',
            'sections' => [
                'Expected standard' => ['Use the least disruptive effective action, give reasons, preserve logs, protect credentials, and avoid acting in personal disputes when another administrator can review.', 'Role changes, page protection, bot publication, blocks, and moderation decisions must be auditable.'],
                'Review and correction' => ['Administrators should explain contested actions and correct mistakes promptly.', 'Serious misuse may result in removal of permissions, account blocking, or escalation to the site owner.'],
            ],
        ],
    ];
}

function policy_by_slug(string $slug): ?array
{
    $catalog = policy_catalog();
    return $catalog[$slug] ?? null;
}
