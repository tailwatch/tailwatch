import React from "react";
import {
  ArrowDownToLine,
  ArrowUpFromLine,
  CircleAlert,
  Crown,
  Hand,
  KeyRound,
  ShieldCheck,
} from "lucide-react";
import { cn } from "../../../Components/Utils/cn";
import { alertOrange, orangeBorder, secondaryGradient } from "../../../Components/Utils/Colors/Colors";
import MigrationNote from "../Components/MigrationNote";
import MigrationSummary from "../MigrationSummary/MigrationSummary";

/**
 * Where moving a site begins: which direction, and nothing else.
 *
 * It asks the one question that decides everything after it — is this site the one being
 * replaced, or the one being copied — and shows the rest only when it applies.
 *
 * The three routes carry equal weight as three cards, and each says the thing people
 * actually get wrong: which of the two websites ends up replaced. By hand is a card like
 * the others because, for two sites that cannot reach each other, it is not a footnote —
 * it is the only route there is.
 */

const StartSkeleton = () => (
  <div className="space-y-4" aria-hidden="true">
    <div className="h-7 w-64 animate-pulse rounded-lg bg-gray-100" />
    <div className="h-4 w-full max-w-xl animate-pulse rounded bg-gray-100" />
    <div className="grid gap-3 sm:grid-cols-3">
      <div className="h-[168px] animate-pulse rounded-2xl bg-gray-100" />
      <div className="h-[168px] animate-pulse rounded-2xl bg-gray-100" />
      <div className="h-[168px] animate-pulse rounded-2xl bg-gray-100" />
    </div>
  </div>
);

/**
 * One of the three ways to move a site.
 *
 * Taller than a row and narrower than the page on purpose: three of them side by side is
 * the whole decision at one glance, and the icon, the sentence and the consequence are
 * read in that order.
 *
 * A route that needs Tailwatch Pro, or a plan that includes it, looks like a locked card on
 * the Features page: the same amber colours, the Upgrade label in its corner and an Upgrade
 * button at its foot, which is the one thing on it that opens the pricing page. The card
 * itself is a plain box that does nothing.
 */
const RouteCard = ({
  icon: Icon,
  title,
  body,
  chip,
  chipTone = "neutral",
  upgradeUrl = "",
  disabled,
  onClick,
}) => {
  const upgrade = !!upgradeUrl;
  const Root = upgrade ? "div" : "button";
  const rootProps = upgrade ? { "aria-disabled": "true" } : { type: "button", onClick, disabled };

  return (
    <Root
      {...rootProps}
      className={cn(
        "group relative flex h-full flex-col gap-3 overflow-hidden rounded-2xl border p-4 text-left transition-all sm:p-5",
        "focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600/30",
        upgrade
          ? cn(secondaryGradient, orangeBorder, "shadow-lg")
          : disabled
          ? "cursor-not-allowed border-gray-200 bg-gray-50/60 opacity-70"
          : "border-gray-200 bg-white hover:-translate-y-0.5 hover:border-brand-600/40 hover:shadow-lg"
      )}
    >
      {upgrade && (
        <span
          className={cn(
            "absolute right-0 top-0 inline-flex items-center gap-1 rounded-bl-lg px-3 py-1 text-xs font-medium text-white",
            alertOrange
          )}
        >
          <Crown size={11} aria-hidden="true" />
          Upgrade
        </span>
      )}

      <span
        className={cn(
          "flex h-11 w-11 items-center justify-center rounded-xl border transition-colors",
          upgrade
            ? "border-orange-200 bg-white/70 text-orange-500"
            : disabled
            ? "border-gray-200 bg-white text-gray-400"
            : "border-brand-100 bg-brand-50 text-brand-600 group-hover:border-brand-600/30 group-hover:bg-brand-600 group-hover:text-white"
        )}
      >
        <Icon className="h-5 w-5" aria-hidden="true" />
      </span>

      <span className="flex-1">
        <span className="block text-[15px] font-semibold leading-snug text-gray-900">
          {title}
        </span>
        <span className={cn("mt-1.5 block text-[13px] leading-relaxed", upgrade ? "text-gray-600" : "text-gray-500")}>
          {body}
        </span>
      </span>

      {chip && (
        <span
          className={cn(
            "w-fit rounded-full border px-2 py-0.5 text-[10.5px] font-semibold uppercase tracking-wide",
            chipTone === "warning"
              ? "border-amber-200 bg-amber-50 text-amber-700"
              : chipTone === "good"
              ? "border-emerald-200 bg-emerald-50 text-emerald-700"
              : "border-gray-200 bg-gray-50 text-gray-500"
          )}
        >
          {chip}
        </span>
      )}

      {upgrade && (
        <span className="flex items-center border-t border-amber-200 pt-3">
          <a
            href={upgradeUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 rounded-lg bg-gradient-to-b from-amber-400 to-amber-600 px-4 py-2 text-xs sm:text-sm font-semibold uppercase tracking-wider !text-white hover:!text-white focus:!text-white !no-underline hover:!no-underline transition-colors duration-200 hover:from-amber-500 hover:to-amber-700"
          >
            <Crown className="h-3.5 w-3.5" aria-hidden="true" />
            <span>Upgrade</span>
          </a>
        </span>
      )}
    </Root>
  );
};

const MigrationStart = ({ migration, onBring, onAway, onByHand, settings }) => {
  const { ready, status, pairing, proPluginActive, planBlocked, migrationOff } = migration;

  if (!ready) return <StartSkeleton />;

  // An automatic move needs the Pro plugin and the plan. The by-hand route needs neither,
  // which is why an upgrade notice must never take the whole screen: it would be telling
  // someone they cannot do the thing they can do.
  const locked = !proPluginActive;
  // Without the plugin or without the plan the answer is the same, so is the badge. It opens
  // the pricing page in a new tab, from the address the plugin hands the dashboard.
  const needsUpgrade = locked || !!planBlocked;
  const upgradeUrl = needsUpgrade
    ? `${window.tailwatch_ajax?.urls?.pricing || "https://wptailwatch.com/pricing/"}?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=pro_upgrade&utm_content=migration_card`
    : "";

  // The switch is off, and it governs all three routes equally — this is the site refusing
  // to be migrated at all, not one road being closed. Every card goes with it, the manual
  // one included: it is the route that works when nothing else does, and letting someone
  // walk through picking a migration file only to be refused at the upload is the failure
  // this screen exists to prevent. Change Settings stays live, because it is the way back.
  const cannotStart = locked || planBlocked || migrationOff;
  const ended = status?.phase === "done" || status?.phase === "failed";

  return (
    <div className="w-full space-y-5">
      {locked && (
        <MigrationNote icon={Crown} tone="warning" title="Moving a site automatically needs the Pro plugin.">
          The two sites talk to each other directly, so nothing has to be carried across.
          You can still move a site manually below — it works between any two sites.
        </MigrationNote>
      )}

      {/* The last transfer, on its own, above the screen that starts the next one. */}
      {ended && <MigrationSummary migration={migration} />}

      {/* This site has already handed a key out and nobody has spent it yet. Said here
          because it is the one thing this site is currently doing, and the route it
          belongs to is the one nobody would think to open to find out. */}
      {pairing?.pending && (
        <MigrationNote
          icon={KeyRound}
          tone="brand"
          title="A connection key from this site is still active."
          action={
            <button
              type="button"
              onClick={onAway}
              className="whitespace-nowrap rounded-md border border-brand-600/30 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-700 transition-colors hover:bg-brand-50"
            >
              Manage it
            </button>
          }
        >
          It expires by itself within the hour, or you can revoke it.
        </MigrationNote>
      )}

      {/* Heading and settings share the line, rather than the button having a row to
          itself above the screen. */}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 className="text-fluid-xl font-semibold tracking-tight text-gray-900">
            Move a site
          </h2>
          <p className="mt-2 max-w-2xl text-fluid-sm leading-relaxed text-gray-500">
            A migration always involves two sites, and only one of them is in front of you.
            Start by saying which of the two this one is.
          </p>
        </div>
        {settings && <div className="shrink-0">{settings}</div>}
      </div>

      {/* Said before anything is pressed, rather than leaving it to be discovered from a
          refusal — the same reason the plan rides on every status poll. */}
      {proPluginActive && planBlocked && (
        <MigrationNote icon={CircleAlert}>
          {pairing?.plan_message ||
            "Your Tailwatch plan does not include moving a site automatically. You can still move this site manually — it works between any two sites."}
        </MigrationNote>
      )}

      <div className="grid gap-3 sm:grid-cols-3">
        <RouteCard
          icon={ArrowDownToLine}
          title="Bring a site here"
          body="Paste the key another site gave you, choose one of its backups, and this site is replaced with it."
          chip={migrationOff ? "Switched off" : needsUpgrade ? "" : "This site is replaced"}
          chipTone={migrationOff ? "neutral" : "warning"}
          upgradeUrl={upgradeUrl}
          disabled={cannotStart}
          onClick={onBring}
        />
        <RouteCard
          icon={ArrowUpFromLine}
          title="Move this site away"
          body="Hand over a key for one of this site's backups. The site you give it to pulls the backup and replaces itself."
          chip={migrationOff ? "Switched off" : needsUpgrade ? "" : "Nothing here changes"}
          chipTone={migrationOff ? "neutral" : "good"}
          upgradeUrl={upgradeUrl}
          disabled={cannotStart}
          onClick={onAway}
        />
        <RouteCard
          icon={Hand}
          title="Move it manually"
          body="Carry the backup across yourself. Slower, and it always works — including between two sites that cannot reach each other."
          chip={migrationOff ? "Switched off" : "Works anywhere"}
          disabled={migrationOff}
          onClick={onByHand}
        />
      </div>

      <MigrationNote icon={ShieldCheck}>
        Nothing is replaced without a confirmation that names both sites, and a backup that
        arrives incomplete is refused rather than restored half-finished.
      </MigrationNote>
    </div>
  );
};

export default MigrationStart;
