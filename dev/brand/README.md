# Brand assets

Sources for the Anchovy organisation identity. Not part of the plugin: `dev/` is
`export-ignore`d in `.gitattributes`, so none of this reaches a site that installs the package.

| File | Use |
|---|---|
| `anchovy-logo-filled.svg` | Organisation logo, brand disc with the mark knocked out. Use this for the Craft Console avatar, which crops to a circle. |
| `anchovy-logo-mark.svg` | The mark alone, for documents and anywhere a solid disc would be too heavy. |
| `anchovy-logo-400.png` | 400×400 export of the filled logo, for Console upload (2× the 200×200 display size). |
| `anchovy-logo-200.png` | 200×200 export, display size. |

Brand colour: `#0E5C63`.

The plugin icon lives at `src/icon.svg` rather than here, because Craft reads it from the
package and it ships in the release archive. Changing it requires a new tag.

Regenerate the PNGs after editing an SVG:

```sh
rsvg-convert -w 400 -h 400 dev/brand/anchovy-logo-filled.svg -o dev/brand/anchovy-logo-400.png
rsvg-convert -w 200 -h 200 dev/brand/anchovy-logo-filled.svg -o dev/brand/anchovy-logo-200.png
```
