import React, { useState } from "react";
import { CircleAlert, KeyRound } from "lucide-react";
import InputField from "../../../Components/Fields/InputField.jsx";
import ActionButton from "../../../Components/Buttons/ActionButton.jsx";
import MigrationNote from "../Components/MigrationNote";
import ScreenHeader from "../Components/ScreenHeader";
import ActionBar from "../Components/ActionBar";
import { parseConnectionToken } from "../connectionToken.js";
import { BRING_STEPS } from "./steps";

/**
 * Pairing with the site being moved here.
 *
 * One paste carries both halves — the other site's address and the key it minted —
 * because two fields to copy between two machines is two chances to copy the wrong thing.
 * The two-field form stays for the case where a string can be read aloud but not pasted:
 * a phone call, a screenshot, a locked-down machine.
 *
 * Nothing here trusts the key: it is unpacked in the browser and both halves go to the
 * same call the typed form uses, which checks the address through the site's own guard and
 * the key against the source's stored hash.
 */
const ConnectByKey = ({
  busy,
  blocked,
  pairedWith,
  onConnect,
  onResume,
  onBack,
}) => {
  const [token, setToken] = useState("");
  const [siteUrl, setSiteUrl] = useState("");
  const [key, setKey] = useState("");
  const [typed, setTyped] = useState(false);
  const [problem, setProblem] = useState("");

  const submit = () => {
    if (typed) {
      if (!siteUrl.trim() || !key.trim()) {
        setProblem("Both the address and the key are needed.");
        return;
      }
      setProblem("");
      onConnect(siteUrl.trim(), key.trim());
      return;
    }

    const parsed = parseConnectionToken(token);
    if (parsed.error) {
      setProblem(parsed.error);
      return;
    }
    setProblem("");
    onConnect(parsed.siteUrl, parsed.key);
  };

  const ready = typed ? siteUrl.trim() && key.trim() : token.trim();

  return (
    <div className="w-full space-y-5">
      <ScreenHeader
        eyebrow="Bring a site here"
        title="Connect to the site you are moving from"
        lede="It generates a key on its own Migration screen and you paste it here. Nothing on either site changes when you connect — you choose the backup next."
        steps={BRING_STEPS}
        step={0}
        onBack={onBack}
        disabled={busy}
      />

      {blocked && <MigrationNote icon={CircleAlert} tone="warning">{blocked}</MigrationNote>}

      {/* A pairing this site already made, before offering to make another. A second key
          can only be spent once, so someone who still has a live pairing should continue
          it rather than go back to the other site for a key they do not need. */}
      {pairedWith && (
        <MigrationNote
          icon={KeyRound}
          tone="brand"
          title={`Already paired with ${pairedWith}.`}
          action={
            <ActionButton
              onClick={onResume}
              isLoading={busy}
              loadingText="Asking..."
              defaultText="Choose a backup"
              isDisabled={!!blocked || busy}
              className="px-3 py-1"
            />
          }
        >
          Pick up where you left off rather than generating another key over there.
        </MigrationNote>
      )}

      <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
        {typed ? (
          <div className="space-y-3">
            <InputField
              label="The other site's address"
              value={siteUrl}
              onChange={(e) => {
                setSiteUrl(e.target.value);
                setProblem("");
              }}
              placeholder="https://example.com"
              disabled={busy}
            />
            <InputField
              label="Pairing key from that site"
              value={key}
              onChange={(e) => {
                setKey(e.target.value);
                setProblem("");
              }}
              placeholder="64 characters"
              disabled={busy}
            />
          </div>
        ) : (
          <InputField
            label="Connection key from the other site"
            value={token}
            onChange={(e) => {
              setToken(e.target.value);
              setProblem("");
            }}
            placeholder="tw1..."
            disabled={busy}
          />
        )}

        {problem && <p className="mt-2 text-xs text-red-700">{problem}</p>}

        <button
          type="button"
          onClick={() => {
            setTyped(!typed);
            setProblem("");
          }}
          className="mt-3 text-xs text-gray-600 hover:underline"
        >
          {typed
            ? "Paste one connection key instead"
            : "Enter the address and key separately instead"}
        </button>
      </div>

      <ActionBar note="The key is single use and expires an hour after it was made. Connecting spends it, and changes nothing on either site.">
        <ActionButton
          onClick={submit}
          isLoading={busy}
          loadingText="Pairing..."
          defaultText="Connect"
          isDisabled={busy || !!blocked || !ready}
        />
      </ActionBar>
    </div>
  );
};

export default ConnectByKey;
