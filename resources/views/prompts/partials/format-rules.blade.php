@if ($hasHtmlUnits)
HTML formatting rules:
- Preserve all HTML tags exactly as they appear (e.g. bold, italic, links, custom span tags).
- Do not translate HTML tag attributes (href, data-*, class, etc.).
- Do not add or remove HTML tags.
- Structural set placeholders (self-closing tags in the format x-set-N) must be kept verbatim.
@endif
@if ($hasMarkdownUnits)
Markdown formatting rules:
- Preserve all Markdown formatting exactly (bold, italic, links, images, headings, lists, code).
- Do not translate link URLs or image sources.
- Do not add or remove Markdown syntax.
@endif
