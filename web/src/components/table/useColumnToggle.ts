import { useCallback, useState } from "react";

export default function useColumnToggle<T extends string>(): {
  activeKey: T | null;
  toggle: (key: T) => void;
  isActive: (key: T) => boolean;
  reset: () => void;
} {
  const [activeKey, setActiveKey] = useState<T | null>(null);

  const toggle = useCallback((key: T) => {
    setActiveKey((current) => (current === key ? null : key));
  }, []);

  const isActive = useCallback(
    (key: T) => activeKey === key,
    [activeKey]
  );

  const reset = useCallback(() => {
    setActiveKey(null);
  }, []);

  return { activeKey, toggle, isActive, reset };
}
