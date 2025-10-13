import { useState } from "react";
import { Link } from "react-router-dom";
import "./AdminTree.css";

type AdminTreeNode = {
  id: string;
  label: string;
  to?: string;
  href?: string;
  children?: AdminTreeNode[];
  defaultExpanded?: boolean;
};

const ADMIN_TREE: AdminTreeNode[] = [
  {
    id: "settings",
    label: "Settings",
    defaultExpanded: true,
    children: [
      {
        id: "settings-theme",
        label: "Theme",
        defaultExpanded: true,
        children: [
          { id: "settings-theme-config", label: "Theme Settings", to: "/admin/settings/theming" },
          { id: "settings-theme-designer", label: "Theme Designer", to: "/admin/settings/theme-designer" },
        ],
      },
      { id: "settings-branding", label: "Branding", to: "/admin/settings/branding" },
      { id: "settings-core", label: "Core Settings", to: "/admin/settings/core" },
    ],
  },
  { id: "roles", label: "Roles", to: "/admin/roles" },
  { id: "users", label: "Users", to: "/admin/users" },
  { id: "user-roles", label: "User Roles", to: "/admin/user-roles" },
  { id: "audit", label: "Audit Logs", to: "/admin/audit" },
  { id: "api-docs", label: "API Documentation", href: "/api/docs" },
];

export default function AdminIndex(): JSX.Element {
  return (
    <section className="container py-3">
      <h1 className="mb-4">Admin</h1>
      <nav aria-label="Admin navigation">
        <ul className="list-unstyled admin-tree m-0" role="tree">
          {ADMIN_TREE.map((node, index) => (
            <TreeItem key={node.id} node={node} level={1} isLast={index === ADMIN_TREE.length - 1} />
          ))}
        </ul>
      </nav>
    </section>
  );
}

type TreeItemProps = {
  node: AdminTreeNode;
  level: number;
  isLast: boolean;
};

function TreeItem({ node, level, isLast }: TreeItemProps): JSX.Element {
  const hasChildren = Array.isArray(node.children) && node.children.length > 0;
  const [expanded, setExpanded] = useState(node.defaultExpanded ?? level === 1);

  const toggleSlot = hasChildren ? (
    <button
      type="button"
      className="btn btn-sm btn-outline-secondary admin-tree-toggle"
      onClick={() => setExpanded((prev) => !prev)}
      aria-label={`${expanded ? "Collapse" : "Expand"} ${node.label}`}
      aria-expanded={expanded}
    >
      {expanded ? "−" : "+"}
    </button>
  ) : null;

  const content = node.to ? (
    <Link className="link-body-emphasis admin-tree-link" to={node.to}>
      {node.label}
    </Link>
  ) : node.href ? (
    <a className="link-body-emphasis admin-tree-link" href={node.href}>
      {node.label}
    </a>
  ) : (
    <span className="text-body">{node.label}</span>
  );

  return (
    <li
      className="admin-tree-item"
      role="treeitem"
      aria-level={level}
      aria-expanded={hasChildren ? expanded : undefined}
      data-level={level}
      data-last={isLast}
      data-has-children={hasChildren ? "true" : "false"}
    >
      <div className={`admin-tree-node d-inline-flex align-items-center${toggleSlot ? " gap-2" : ""}`}>
        {toggleSlot}
        {content}
      </div>
      {hasChildren && expanded ? (
        <ul role="group" className="admin-tree-branch">
          {(node.children ?? []).map((child, index) => (
            <TreeItem
              key={child.id}
              node={child}
              level={level + 1}
              isLast={index === (node.children?.length ?? 0) - 1}
            />
          ))}
        </ul>
      ) : null}
    </li>
  );
}
