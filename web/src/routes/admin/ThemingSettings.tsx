import ThemeConfigurator from "./ThemeConfigurator";

export default function ThemingSettings(): JSX.Element {
  return (
    <section className="container py-3">
      <h1 className="mb-3">Theme Settings</h1>
      <ThemeConfigurator />
    </section>
  );
}
