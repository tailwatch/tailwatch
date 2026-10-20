/**
 * Read a module's feature switches out of any status response.
 *
 * Every gated action answers one of two ways. The dispatcher refuses a switched-off
 * feature with `success: false` and the pair — `feature_enable` / `parent_enable` — and
 * lets everything else through with `success: true`.
 *
 * Both halves are information. Reading the pair only from a refusal would make those flags
 * a one-way latch: once the site refused, `parentEnable` would stay false, and a feature
 * switched back on from another device would keep showing the card that says it is off
 * while every reply said "allowed".
 *
 * So a success is read as what it is: proof the gate passed. A response that got through
 * could not have got through with either switch off.
 *
 * Both setters are optional — some callers only track one of the two — and anything that
 * is not a recognised answer leaves the flags exactly as they were, because "the request
 * failed" is not the same fact as "the feature is off" and must never be shown as one.
 *
 * @param {object} response  The raw axios response.
 * @param {function} [setFeatureEnable]
 * @param {function} [setParentEnable]
 */
export const readFeatureGate = (response, setFeatureEnable, setParentEnable) => {
  const success = response?.data?.success;
  const apply = (feature, parent) => {
    if (typeof setFeatureEnable === "function") setFeatureEnable(feature);
    if (typeof setParentEnable === "function") setParentEnable(parent);
  };

  if (success === true) {
    apply(true, true);
    return;
  }

  if (success === false) {
    const payload = response?.data?.data;
    // A refusal that names neither switch is some other failure — a bad nonce, a missing
    // action — and saying "the feature is off" about it would be an invention.
    if (payload && typeof payload === "object") {
      const { feature_enable, parent_enable } = payload;
      if (feature_enable !== undefined || parent_enable !== undefined) {
        apply(feature_enable, parent_enable);
      }
    }
  }
};

export default readFeatureGate;
