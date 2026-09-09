# Adaptive venue hero polish

Follow-up after production review of the public venue page.

## Requirements

- switch `.venue-hero` to one column below 1400px and keep that layout as the viewport gets narrower;
- keep the information summary in a separate card below the photo at every width below 1400px, without an intermediate overlay layout;
- turn the hero image into the complete venue gallery slider, with compact vertical navigation controls and the existing full-screen viewer on slide click;
- make the component heading flex-wrap based on available space, so rating stars never collide with the venue title;
- keep the rating numeric value hidden and available only through the existing tooltip/aria-label;
- keep working hours and occupied slots in one horizontal row inside that card; on very narrow screens the row scrolls horizontally instead of compressing its content;
- remove address details from the hero card and make `Адрес` the first content section, followed by `Игры и мероприятия`, options, schedule, posts, and reviews;
- remove the standalone gallery section and its navigation item;
- keep the URL fragment synchronized with anchor clicks and the active section while scrolling;
- show only the metro station name; expose the line name through a tooltip on the line-coloured bullet, without a help icon or underlined trigger;
- place the venue type and open/closed state over the photo instead of consuming space in the information card;
- replace the occupied-slot list with a full-width single-row nine-day calendar starting today: confirmed occupancy is green, pending/held occupancy is orange, and free days use a light neutral surface; keep a readable cell width through horizontal overflow on narrow screens;
- open a day schedule in a modal and allow navigation through the same nine-day window, with navigation to a day before today disabled.

## Implementation notes

At 1400px and above the media and summary remain two desktop columns. Below that breakpoint they occupy two explicit grid rows, which removes the overlapping intermediate layout entirely. The summary contains two horizontal information groups; below 520px the row becomes an internal horizontal scroller.

The server renders the activities section shell in its final position before JavaScript loads its JSON data. This gives anchor navigation and scroll tracking a stable target, while the activity data itself remains progressively enhanced. Click navigation uses `history.pushState`; scroll-driven activation uses `replaceState` so scrolling does not create a noisy browser history.

The occupancy projection is prepared server-side in one nine-day query window. A confirmed booking has priority over pending or held bookings when selecting the day colour. The modal reuses the projected data, so switching days does not generate additional requests or N+1 event lookups.
