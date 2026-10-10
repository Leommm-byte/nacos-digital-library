"""
Builds the app icons and the link-preview image from the NACOS logo.
Run once after changing the logo: python3 scripts/make-icons.py
(needs Pillow, and fonttools + brotli to read the self-hosted font).
"""
import io
from pathlib import Path

from fontTools.ttLib import TTFont
from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent.parent
GREEN = (10, 125, 54)
DARK_GREEN = (6, 79, 35)
YELLOW = (255, 204, 0)
WHITE = (255, 255, 255)

logo = Image.open(ROOT / 'legacy/assets/images/NACOS_LOGO.png').convert('RGBA')
# The logo is a disc on white: keep only the disc.
mask = Image.new('L', logo.size, 0)
ImageDraw.Draw(mask).ellipse((6, 6, logo.width - 6, logo.height - 6), fill=255)
logo.putalpha(mask)


def font(size, weight):
    woff2 = ROOT / 'node_modules/@fontsource-variable/plus-jakarta-sans/files/plus-jakarta-sans-latin-wght-normal.woff2'
    ttf = io.BytesIO()
    f = TTFont(woff2)
    f.flavor = None
    f.save(ttf)
    ttf.seek(0)
    face = ImageFont.truetype(ttf, size)
    face.set_variation_by_axes([weight])
    return face


def icon(size, logo_share, background):
    canvas = Image.new('RGBA', (size, size), background)
    side = round(size * logo_share)
    canvas.alpha_composite(logo.resize((side, side), Image.LANCZOS), ((size - side) // 2, (size - side) // 2))
    return canvas


out = ROOT / 'public/icons'
out.mkdir(exist_ok=True)

# "any": the round logo on a white disc-friendly square.
for size in (192, 512):
    icon(size, 0.92, WHITE + (255,)).save(out / f'icon-{size}.png', optimize=True)

# "maskable": launchers crop to a circle or squircle, so the logo stays
# inside the central 80% safe zone.
icon(512, 0.74, WHITE + (255,)).save(out / 'maskable-512.png', optimize=True)

# Link previews (Open Graph / Twitter), 1200x630.
card = Image.new('RGBA', (1200, 630), GREEN + (255,))
draw = ImageDraw.Draw(card)
draw.rectangle((0, 560, 1200, 630), fill=DARK_GREEN)
draw.rectangle((0, 552, 1200, 560), fill=YELLOW)
draw.ellipse((70, 120, 450, 500), fill=WHITE)
card.alpha_composite(logo.resize((360, 360), Image.LANCZOS), (80, 130))
draw.text((510, 175), 'NACOS', font=font(96, 800), fill=WHITE)
draw.text((510, 285), 'YabaTech', font=font(72, 700), fill=YELLOW)
draw.text((512, 395), 'Books, past questions, elections', font=font(34, 500), fill=WHITE)
draw.text((512, 440), 'and student services.', font=font(34, 500), fill=WHITE)
draw.text((70, 578), 'Nigeria Association of Computing Students · Yaba College of Technology', font=font(24, 500), fill=WHITE)
card.convert('RGB').save(ROOT / 'public/images/share.png', optimize=True)
