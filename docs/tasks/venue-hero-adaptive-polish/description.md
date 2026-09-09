# Adaptive venue hero polish

Follow-up after production review of the public venue page.

## Requirements

- switch `.venue-hero` to one column below 1400px and keep that layout as the viewport gets narrower;
- overlay the information summary on the hall photo without clipping or letting the content escape the hero bounds;
- remove the old fixed-height pressure below 1024px and let the shared grid row grow from the summary content;
- keep the hall photo covering the resulting hero height;
- animate the dark gradient on hover/focus so it expands further to the left and becomes slightly less transparent;
- make the component heading flex-wrap based on available space, so rating stars never collide with the venue title;
- keep the rating numeric value hidden and available only through the existing tooltip/aria-label;
- at 768px and below, stop overlaying the summary: place it in a separate card below the photo;
- keep address, working hours, and occupied slots in one horizontal row inside that card; on very narrow screens the row scrolls horizontally instead of compressing its content.
- show only the metro station name; expose the line name through a tooltip on the line-coloured bullet, without a help icon or underlined trigger;
- place the venue type and open/closed state over the photo instead of consuming space in the information card;
- replace the occupied-slot list with a nine-day calendar starting today: confirmed occupancy is green, pending/held occupancy is orange, and free days use a light neutral surface;
- open a day schedule in a modal and allow navigation through the same nine-day window, with navigation to a day before today disabled.

## Implementation notes

The media and summary share the same CSS Grid cell instead of using an absolutely positioned summary. This keeps the visual overlay while allowing the summary to participate in row sizing, avoiding vertical clipping at intermediate widths.

At 768px and below the same grid is deliberately split back into two rows. The summary restores the standard card surface and its three detail groups use a horizontal grid; below 520px that grid becomes an internal horizontal scroller so the labels and booking data remain readable.

The occupancy projection is prepared server-side in one nine-day query window. A confirmed booking has priority over pending or held bookings when selecting the day colour. The modal reuses the projected data, so switching days does not generate additional requests or N+1 event lookups.
