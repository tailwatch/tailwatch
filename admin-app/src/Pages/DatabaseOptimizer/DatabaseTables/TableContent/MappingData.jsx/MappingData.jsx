import {
  MessageSquareX, MailX, FileClock, Files, Trash2, MessagesSquare,
  Hourglass, Settings, FileQuestion, FileCheck, Mails, ScrollText, FolderSearch
} from "lucide-react";

// Renders a static Lucide icon. The `ref` argument is accepted to preserve the
// previous icon(ref) call signature used by TableContent, but is ignored since
// Lucide icons render synchronously (no animation ref needed).
const DatabaseIcon = (Icon) => () => (
  <Icon size={24} className="text-[#007980]" />
);

export const MappingData = {
  spam_comments: {
    description: "Clean all spam comments",
    icon: DatabaseIcon(MessageSquareX),
  },
  trashed_comments: {
    description: "Clean all trashed comments",
    icon: DatabaseIcon(MailX),
  },
  post_revision: {
    description: "Clean all post revisions",
    icon: DatabaseIcon(FileClock),
  },
  auto_drafts: {
    description: "Clean all auto save drafts",
    icon: DatabaseIcon(Files),
  },
  trashed_posts: {
    description: "Clean all trashed posts and pages",
    icon: DatabaseIcon(Trash2),
  },
  trackbacks_pingbacks: {
    description: "Clean all trackbacks and pingbacks",
    icon: DatabaseIcon(MessagesSquare),
  },
  expired_transients: {
    description: "Clean expired transient options",
    icon: DatabaseIcon(Hourglass),
  },
  all_transients: {
    description: "Clean all transient options",
    icon: DatabaseIcon(Settings),
  },
  orphaned_post_meta: {
    description: "Clean all orphaned post meta records",
    icon: DatabaseIcon(FileQuestion),
  },
  logs_activity: {
    label: "Activity Logs",
    description: "Clean all Activity Logs",
    icon: DatabaseIcon(FileCheck),
  },
  email_logs: {
    description: "Clean all Email Logs",
    icon: DatabaseIcon(Mails),
  },
  ajax_logs: {
    label: "Network Logs",
    description: "Clean all Network Logs",
    icon: DatabaseIcon(ScrollText),
  },
  monitoring_logs: {
    label: "Error Logs",
    description: "Clean all Error Logs",
    icon: DatabaseIcon(FolderSearch),
  },
};
