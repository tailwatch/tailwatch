/**
 * Class name joiner: flattens arrays, drops anything falsy.
 *
 * Usage: cn("base", condition && "extra", className)
 *
 * Kept dependency-free: the only thing the conditional markup here needs is "leave out
 * the ones that are false".
 */
export const cn = (...args) => args.flat(Infinity).filter(Boolean).join(" ");

export default cn;
