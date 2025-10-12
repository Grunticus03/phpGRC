import BrandingCard from "./branding/BrandingCard";

export default function BrandingSettings(): JSX.Element {
  return (
    <section className="container py-3">
      <h1 className="mb-3">Branding</h1>
      <BrandingCard />
    </section>
  );
}
