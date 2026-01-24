from PIL import Image, ImageDraw, ImageFont
import os
import unicodedata

# ==========================
# CONFIGURATION
# ==========================
SIZE = (300, 300)
BG_COLOR = "#0A1A2F"  # very dark blue
TEXT_COLOR = "white"
FONT_SIZE = 120
OUTPUT_FOLDER = "avatars"

# Try to use Arial or fallback
try:
    FONT = ImageFont.truetype("arial.ttf", FONT_SIZE)
except:
    FONT = ImageFont.load_default()

# ==========================
# NAME LIST
# ==========================
names = [
"Maria A. Tănasă",
"Alin M. Olteanu",
"Beatrice G. Netu",
"Alex C. Zamfir",
"Andreea M. Lungu",
"Mihai I. Ghiaur",
"Mălina Cioșnar"
]

# ==========================
# HELPER FUNCTIONS
# ==========================

def get_initials(name):
    """Return first + last initial."""
    parts = [p for p in name.split() if p.strip()]
    if len(parts) == 1:
        return parts[0][0].upper()
    return (parts[0][0] + parts[-1][0]).upper()


def snake_case_filename(name):
    """Convert name to safe snake-case filename."""
    nfkd = unicodedata.normalize("NFKD", name)
    no_accent = "".join(c for c in nfkd if not unicodedata.combining(c))
    clean = ""

    for c in no_accent.lower():
        if c.isalnum():
            clean += c
        elif c in [' ', '.', '_', '-']:
            clean += "-"
        # else skip symbol

    # collapse multiple dashes
    while "--" in clean:
        clean = clean.replace("--", "-")

    return clean.strip("-") + ".png"

# ==========================
# GENERATE AVATARS
# ==========================

os.makedirs(OUTPUT_FOLDER, exist_ok=True)

for name in names:
    initials = get_initials(name)
    filename = snake_case_filename(name)

    img = Image.new("RGB", SIZE, BG_COLOR)
    draw = ImageDraw.Draw(img)

    # Measure text using textbbox (works in Pillow 10+)
    bbox = draw.textbbox((0, 0), initials, font=FONT)
    text_w = bbox[2] - bbox[0]
    text_h = bbox[3] - bbox[1]

    # Exact center placement
    x = (SIZE[0] - text_w) / 2 - bbox[0]
    y = (SIZE[1] - text_h) / 2 - bbox[1]

    draw.text((x, y), initials, fill=TEXT_COLOR, font=FONT)

    img.save(os.path.join(OUTPUT_FOLDER, filename))

print("Done! Avatars saved in the 'avatars' folder.")
