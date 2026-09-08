# Adaptive venue hero polish

Follow-up after production review of the public venue page.

## Requirements

- switch `.venue-hero` to one column below 1400px and keep that layout as the viewport gets narrower;
- overlay the information summary on the hall photo without clipping or letting the content escape the hero bounds;
- remove the old fixed-height pressure below 1024px and let the shared grid row grow from the summary content;
- keep the hall photo covering the resulting hero height;
- animate the dark gradient on hover/focus so it expands further to the left and becomes slightly less transparent;
- make the component heading flex-wrap based on available space, so rating stars never collide with the venue title;
- keep the rating numeric value hidden and available only through the existing tooltip/aria-label.

## Implementation notes

The media and summary share the same CSS Grid cell instead of using an absolutely positioned summary. This keeps the visual overlay while allowing the summary to participate in row sizing, avoiding vertical clipping at intermediate widths.
