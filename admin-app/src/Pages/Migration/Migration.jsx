import React, { useEffect, useState } from "react";
import Header from "../../Components/Header/Header";
import InfoBar from "../../Components/InfoBar/InfoBar.jsx";
import LockCard from "../../Components/LockCard/LockCard.jsx";
import ShowCanvas from "../../Components/ShowCanvasButton/ShowCanvas.jsx";
import { useMigration } from "./useMigration.jsx";
import MigrationStart from "./MigrationStart/MigrationStart";
import MigrationBring from "./MigrationBring/MigrationBring";
import MigrationAway from "./MigrationAway/MigrationAway";
import MigrationByHand from "./MigrationByHand/MigrationByHand";
import MigrationProgress from "./MigrationProgress/MigrationProgress";

/**
 * Moving a site, from both ends.
 *
 * A migration always involves two sites and only one of them is in front of the person
 * doing it, so what this screen shows depends on which of the two this site is — and that
 * is a question with an answer, not a pair of tabs to leave open.
 *
 * There are two ways to make the crossing:
 *
 * - **Automatic** — the two sites talk directly. Fast, and nothing is carried by hand, but
 *   it needs each site to be able to reach the other.
 * - **By hand** — the other site writes a small migration file describing one backup, the
 *   parts are downloaded and uploaded by a person, and this site reassembles them. Slower,
 *   and it always works: a local build, a site behind a VPN, a server with outbound HTTP
 *   blocked, an install whose address does not resolve yet.
 *
 * Both paths end in exactly the same place — a backup this site can restore — so the
 * restore itself, including rewriting the old site's addresses to this one's, is the same
 * step either way and is deliberately left as a separate, confirmed action.
 *
 * This file is a router and nothing else. The state, the polling, the handlers and the
 * panels' markup live in useMigration and in the screens below, because a page that
 * renders every panel at once can only put one away by hiding it — and can end up offering
 * to start a migration underneath a report of one that has already finished.
 */
const MigrationModule = ({ migration }) => {
  // Which route is being set up, if any. Deliberately not persisted: choosing a route and
  // a backup changes nothing on either site and is over in three presses, while everything
  // that DOES outlive the page — the pairing, the transfer, the import — is read back from
  // the site itself and has its own screen.
  const [flow, setFlow] = useState("");

  // Anything actually moving takes the screen back: a wizard over a running job could only
  // end in a refusal. A by-hand import is the exception — its screen IS the flow, and it
  // is the one job that waits for the operator rather than for a site.
  useEffect(() => {
    if (migration.running || migration.applying) setFlow("");
  }, [migration.running, migration.applying]);

  // Arriving at a job already under way, rather than at a choice. An import waits for the
  // operator, so it keeps its own screen; a transfer and the restore it hands over to are
  // watched rather than set up.
  if (migration.running || migration.applying) {
    return <MigrationProgress migration={migration} />;
  }

  // `finished` as well as `importing`: the last part lands, the import goes idle, and for
  // the moment before the restore takes over there is a result to report. Without it, an
  // import that completed without redirecting would drop the operator back on the route
  // cards with nothing said about the thing they just spent ten minutes uploading.
  if (migration.importing || migration.finished || flow === "hand") {
    return <MigrationByHand migration={migration} onLeave={() => setFlow("")} />;
  }

  if (flow === "bring") {
    return <MigrationBring migration={migration} onLeave={() => setFlow("")} />;
  }

  if (flow === "away") {
    return <MigrationAway migration={migration} onLeave={() => setFlow("")} />;
  }

  return (
    <MigrationStart
      migration={migration}
      onBring={() => setFlow("bring")}
      onAway={() => setFlow("away")}
      onByHand={() => setFlow("hand")}
      settings={
        <ShowCanvas
          featureId={migration.migrationFeature?.featureId}
          onClose={migration.afterFeatureToggle}
          parentEnable={migration.migrationFeature?.parentEnable}
        />
      }
    />
  );
};

const Migration = () => {
  // Called once, here, and handed down. Two instances of this hook would mean two pollers
  // asking the same site the same question, and a screen that believes whichever answered
  // last — which is the failure the module's single poll exists to avoid.
  const migration = useMigration();

  return (
    <div>
      <Header title="Migration" />

      {/* The settings button sits on the start screen's own heading line, beside the thing
          it configures, rather than on a full-width row of its own above everything. */}
      <div className="px-4 pb-4 pt-3">
        {/* Something else on this site owns the process a migration would need. Above the
            flow rather than inside it, because it applies to every route equally. */}
        {migration.inTheWay && (
          <div className="mb-4">
            <InfoBar type="warning" message={migration.inTheWay} />
          </div>
        )}

        {/* Two different "off"s, and they need two different offers. The feature switched
            off entirely -> offer to switch it on here. The feature on but "Allow Migration"
            unticked -> switching it on again would do nothing, so send them to its
            settings instead. */}
        {migration.parentOff && (
          <LockCard
            featureId={migration.migrationFeature?.featureId}
            featureName="Restore & Migration"
            featureDescription="Put a backup back onto this site, or move a whole site between two installs. Both stay off until you switch them on, so nothing can be exported or overwritten by accident."
            isActive={migration.migrationFeature?.isActiveState}
            afterToggleCallback={migration.afterFeatureToggle}
          />
        )}

        {/* No button of its own. The module already carries one Change Settings, on the
            heading line below, and a second copy of the same control a few pixels above it
            is two doors into one room — it reads as though they might do different things. */}
        {migration.migrationOff && (
          <div className="mb-4">
            <InfoBar
              type="warning"
              message="Migration is switched off for this site. Restore & Migration is on, but its Allow Migration box is unticked — open Change Settings and tick it."
            />
          </div>
        )}

        <div className={migration.parentOff ? "pointer-events-none blur-sm filter" : ""}>
          <MigrationModule migration={migration} />
        </div>
      </div>
    </div>
  );
};

export default Migration;
