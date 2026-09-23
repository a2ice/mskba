## Verification targets

CI must cover debit-on-start, duplicate active request rejection, exact split refund on failed callback, dispatch-failure refund, and the existing successful GitHub/OpenAI callback flow. Frontend production verification should use two account tabs and confirm that the second tab enters the busy state and receives the completed image without starting another generation.
