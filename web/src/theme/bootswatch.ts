import ceruleanCss from "bootswatch/dist/cerulean/bootstrap.min.css?url";
import cosmoCss from "bootswatch/dist/cosmo/bootstrap.min.css?url";
import cyborgCss from "bootswatch/dist/cyborg/bootstrap.min.css?url";
import darklyCss from "bootswatch/dist/darkly/bootstrap.min.css?url";
import flatlyCss from "bootswatch/dist/flatly/bootstrap.min.css?url";
import journalCss from "bootswatch/dist/journal/bootstrap.min.css?url";
import literaCss from "bootswatch/dist/litera/bootstrap.min.css?url";
import lumenCss from "bootswatch/dist/lumen/bootstrap.min.css?url";
import luxCss from "bootswatch/dist/lux/bootstrap.min.css?url";
import materiaCss from "bootswatch/dist/materia/bootstrap.min.css?url";
import mintyCss from "bootswatch/dist/minty/bootstrap.min.css?url";
import morphCss from "bootswatch/dist/morph/bootstrap.min.css?url";
import pulseCss from "bootswatch/dist/pulse/bootstrap.min.css?url";
import quartzCss from "bootswatch/dist/quartz/bootstrap.min.css?url";
import sandstoneCss from "bootswatch/dist/sandstone/bootstrap.min.css?url";
import simplexCss from "bootswatch/dist/simplex/bootstrap.min.css?url";
import sketchyCss from "bootswatch/dist/sketchy/bootstrap.min.css?url";
import slateCss from "bootswatch/dist/slate/bootstrap.min.css?url";
import solarCss from "bootswatch/dist/solar/bootstrap.min.css?url";
import spacelabCss from "bootswatch/dist/spacelab/bootstrap.min.css?url";
import superheroCss from "bootswatch/dist/superhero/bootstrap.min.css?url";
import unitedCss from "bootswatch/dist/united/bootstrap.min.css?url";
import vaporCss from "bootswatch/dist/vapor/bootstrap.min.css?url";
import yetiCss from "bootswatch/dist/yeti/bootstrap.min.css?url";
import zephyrCss from "bootswatch/dist/zephyr/bootstrap.min.css?url";

export type BootswatchMode = "light" | "dark";

export type BootswatchThemeMeta = {
  slug: string;
  name: string;
  mode: BootswatchMode;
  href: string;
};

export const BOOTSWATCH_THEMES: readonly BootswatchThemeMeta[] = [
  { slug: "cerulean", name: "Cerulean", mode: "light", href: ceruleanCss },
  { slug: "cosmo", name: "Cosmo", mode: "light", href: cosmoCss },
  { slug: "cyborg", name: "Cyborg", mode: "dark", href: cyborgCss },
  { slug: "darkly", name: "Darkly", mode: "dark", href: darklyCss },
  { slug: "flatly", name: "Flatly", mode: "light", href: flatlyCss },
  { slug: "journal", name: "Journal", mode: "light", href: journalCss },
  { slug: "litera", name: "Litera", mode: "light", href: literaCss },
  { slug: "lumen", name: "Lumen", mode: "light", href: lumenCss },
  { slug: "lux", name: "Lux", mode: "light", href: luxCss },
  { slug: "materia", name: "Materia", mode: "light", href: materiaCss },
  { slug: "minty", name: "Minty", mode: "light", href: mintyCss },
  { slug: "morph", name: "Morph", mode: "light", href: morphCss },
  { slug: "pulse", name: "Pulse", mode: "light", href: pulseCss },
  { slug: "quartz", name: "Quartz", mode: "dark", href: quartzCss },
  { slug: "sandstone", name: "Sandstone", mode: "light", href: sandstoneCss },
  { slug: "simplex", name: "Simplex", mode: "light", href: simplexCss },
  { slug: "sketchy", name: "Sketchy", mode: "light", href: sketchyCss },
  { slug: "slate", name: "Slate", mode: "dark", href: slateCss },
  { slug: "solar", name: "Solar", mode: "dark", href: solarCss },
  { slug: "spacelab", name: "Spacelab", mode: "light", href: spacelabCss },
  { slug: "superhero", name: "Superhero", mode: "dark", href: superheroCss },
  { slug: "united", name: "United", mode: "light", href: unitedCss },
  { slug: "vapor", name: "Vapor", mode: "dark", href: vaporCss },
  { slug: "yeti", name: "Yeti", mode: "light", href: yetiCss },
  { slug: "zephyr", name: "Zephyr", mode: "light", href: zephyrCss },
] as const;

export const BOOTSWATCH_THEME_HREFS: Readonly<Record<string, string>> = BOOTSWATCH_THEMES.reduce(
  (acc, theme) => {
    acc[theme.slug] = theme.href;
    return acc;
  },
  {} as Record<string, string>
);

export function getBootswatchTheme(slug: string): BootswatchThemeMeta | undefined {
  return BOOTSWATCH_THEMES.find((theme) => theme.slug === slug);
}

type BootswatchVariantMap = Partial<Record<BootswatchMode, BootswatchThemeMeta>>;

const buildVariantMeta = (): Record<string, BootswatchVariantMap> =>
  BOOTSWATCH_THEMES.reduce<Record<string, BootswatchVariantMap>>((acc, theme) => {
    acc[theme.slug] = {
      light: { ...theme, slug: `${theme.slug}:primary`, name: "Primary" },
      dark: { ...theme, slug: `${theme.slug}:dark`, name: "Dark" },
    };
    return acc;
  }, {});

export const BOOTSWATCH_THEME_VARIANTS: Readonly<Record<string, BootswatchVariantMap>> =
  buildVariantMeta();

export function getBootswatchVariant(slug: string, mode: BootswatchMode): BootswatchThemeMeta | undefined {
  const variants = BOOTSWATCH_THEME_VARIANTS[slug];
  return variants?.[mode];
}
