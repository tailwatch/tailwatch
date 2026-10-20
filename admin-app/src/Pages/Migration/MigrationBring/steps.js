/**
 * The four steps of bringing a site here, and only the first two happen on this screen.
 *
 * The last two are shown anyway: the transfer runs on the two sites' schedulers and the
 * restore is watched on the Restore screen, but the thing being consented to is all four.
 * Someone who thinks choosing a backup is the end has not been told what they agreed to.
 */
export const BRING_STEPS = ["Connect", "Choose backup", "Transfer", "Restore"];
