import { forwardRef, useEffect, useImperativeHandle } from 'react';
import {
  ShieldCheck, ClipboardCheck, Activity, Network, Send, MailX,
  LockKeyhole, Construction, Bug, Timer, Fingerprint, Bot,
  FileSearch, DatabaseBackup, FileLock2, Ban, Webhook, LifeBuoy, ShieldAlert,
  Database, Replace, FolderSync, BrickWall, Users, Forward, Unlink, Globe,
  Cctv, LineChart, Eye, UserCheck, FolderLock, ListChecks, KeyRound, RefreshCw
} from 'lucide-react';

// Maps each feature to a Lucide icon (ISC-licensed, GPL-compatible). These replace
// the previous animated Lottie icons; the outline style mirrors the originals.
const FEATURE_ICON_MAP = {
  'Security Headers': ShieldCheck,
  'Daily Reports': ClipboardCheck,
  'Activity Logs': Activity,
  'Network Logs': Network,
  'Email/SMTP Logs': Send,
  'Error Logs': MailX,
  'Smart SSL': LockKeyhole,
  'Maintenance Mode': Construction,
  'Malware Guard': Bug,
  'Cron Jobs': Timer,
  'Role-based 2FA': Fingerprint,
  'reCAPTCHA': Bot,
  'File Integrity Watch': FileSearch,
  'Backup Vault': DatabaseBackup,
  'Files & Permissions': FileLock2,
  'Content Restrictions': Ban,
  'Rest API Guard': Webhook,
  'SOS Recovery': LifeBuoy,
  'Login Defender': ShieldAlert,
  'Database Optimizer': Database,
  'Search & Replace': Replace,
  'Site Migration': FolderSync,
  'Firewall': BrickWall,
  'User Management': Users,
  '301 Redirection': Forward,
  'Broken Links': Unlink,
  'Geo-blocking': Globe,
  'Traffic Shield': Cctv,
  'Google Analytics': LineChart,
  'Password Leak Protection': Eye,
  'User Hardening': UserCheck,
  'Hide WP': FolderLock,
  'Hardening Audit': ListChecks,
  'Security Keys Rotation': KeyRound,
  'Updates & Rollback': RefreshCw
};

const FeatureIconMapper = forwardRef(({ title, isActive, isPro, isLocked, size = 24, colorClass = 'text-[#007980]', onReady }, ref) => {
  const IconComponent = FEATURE_ICON_MAP[title];

  // Icons render synchronously now (no async Lottie load), so report ready on mount.
  useEffect(() => {
    if (onReady) {
      onReady();
    }
  }, [onReady]);

  // Preserve the previous play()/stop() ref API as no-ops so callers that drove the
  // old hover animation (FeatureCard) keep working without any changes.
  useImperativeHandle(ref, () => ({ play: () => {}, stop: () => {} }), []);

  if (!IconComponent) {
    if (!isLocked && !isPro && isActive) {
      return <div className="w-3 h-3 rounded-full bg-[#007980]"></div>;
    }
    if (!isLocked && !isPro && !isActive) {
      return <div className="w-3 h-3 rounded-full bg-gray-400"></div>;
    }
    return null;
  }

  // Colour is supplied by the caller so each card matches its own theme
  // (brand teal for normal cards, orange for upgrade/locked cards).
  return <IconComponent size={size} className={colorClass} />;
});

FeatureIconMapper.displayName = 'FeatureIconMapper';

export default FeatureIconMapper;
