/**
 * The pill beside "Connect Account" in the sidebar.
 *
 * Read from the same licence check the header's profile menu uses, so the two always agree.
 * A plan name is only known for a connected site, so it decides first: connecting fills it in
 * before the connection status is checked again. A site that has never been connected sees Free,
 * because connecting a Tailwatch account costs nothing, and a check that could not finish says
 * nothing rather than something wrong.
 */

const FREE_PLANS = ["Free", "Basic"];

export const accountBadge = ({ loading, licenseStatus, planName } = {}) => {
  if (loading) return null;
  const plan = typeof planName === "string" ? planName : "";
  if (plan) {
    if (FREE_PLANS.includes(plan)) {
      return { text: "Free", className: "bg-gray-600 text-gray-100" };
    }
    if (plan === "Agency" || plan.startsWith("Business")) {
      return { text: "Pro", className: "bg-amber-400 text-amber-950" };
    }
    return null;
  }
  if (licenseStatus === "killed") {
    return { text: "Free", className: "bg-teal-500 text-white" };
  }
  return null;
};
