"""
Génère des images de SPÉCIMENS FICTIFS (personnes inventées) pour tester l'OCR
sans utiliser de vraies pièces d'identité :
  - cni_recto.jpg / cni_verso.jpg : carte d'identité CEDEAO (mise en page simplifiée)
  - passeport.jpg                   : page de données d'un passeport (MRZ TD3)
  - cni_photo_inclinee.jpg          : recto photographié de travers, avec bruit

Usage : python make_specimens.py [dossier_sortie]
"""
import os
import sys
import random

from PIL import Image, ImageDraw, ImageFilter, ImageFont

OUT = sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(os.path.abspath(__file__))
SANS = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"
BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
MONO = "/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf"


def font(path, size):
    try:
        return ImageFont.truetype(path, size)
    except OSError:
        return ImageFont.load_default()


def check_digit(data: str) -> str:
    values = {**{str(i): i for i in range(10)}, **{chr(65 + i): 10 + i for i in range(26)}, "<": 0}
    weights = (7, 3, 1)
    return str(sum(values[c] * weights[i % 3] for i, c in enumerate(data)) % 10)


def td1(doc_code, state, number, birth, sex, expiry, nationality, surname, given):
    # Numéro long (> 9 caractères) : règle OACI — 9 premiers + « < » puis la suite + chiffre de contrôle en zone facultative.
    if len(number) > 9:
        num_field, num_check = number[:9], "<"
        optional1 = (number[9:] + check_digit(number)).ljust(15, "<")
    else:
        num_field, num_check = number.ljust(9, "<"), check_digit(number.ljust(9, "<"))
        optional1 = "<" * 15
    l1 = f"{doc_code:<2}"[:2].replace(" ", "<") + state + num_field + num_check + optional1
    l2 = birth + check_digit(birth) + sex + expiry + check_digit(expiry) + nationality + "<" * 11
    composite = check_digit(l1[5:30] + l2[0:7] + l2[8:15] + l2[18:29])
    l2 += composite
    name = (surname.replace(" ", "<") + "<<" + given.replace(" ", "<"))[:30].ljust(30, "<")
    return [l1, l2, name]


def td3(state, number, birth, sex, expiry, nationality, surname, given, personal=""):
    l1 = ("P<" + state + surname.replace(" ", "<") + "<<" + given.replace(" ", "<"))[:44].ljust(44, "<")
    num = number.ljust(9, "<")
    pers = personal.ljust(14, "<")
    l2 = num + check_digit(num) + nationality + birth + check_digit(birth) + sex + expiry + check_digit(expiry) + pers + check_digit(pers)
    l2 += check_digit(l2[0:10] + l2[13:20] + l2[21:43])
    return [l1, l2]


def card(w=1011, h=638, color=(236, 244, 238)):
    img = Image.new("RGB", (w, h), color)
    d = ImageDraw.Draw(img)
    d.rectangle([0, 0, w, 70], fill=(0, 122, 77))
    return img, d


def cni_recto():
    img, d = card()
    d.text((20, 18), "RÉPUBLIQUE DU SÉNÉGAL", font=font(BOLD, 28), fill="white")
    d.text((560, 22), "CARTE D'IDENTITÉ CEDEAO", font=font(BOLD, 24), fill="white")
    d.rectangle([30, 100, 290, 420], fill=(200, 200, 200))
    d.text((85, 250), "PHOTO", font=font(BOLD, 36), fill=(120, 120, 120))
    lab, val = font(SANS, 20), font(BOLD, 28)
    rows = [
        ("N° de la carte d'identité", "2 01 19850512 00123 7"),
        ("Prénoms", "AWA"),
        ("Nom", "NDIAYE"),
        ("Date de naissance", "12/05/1985"),
        ("Lieu de naissance", "DAKAR"),
        ("Sexe", "F"),
        ("Date d'expiration", "11/03/2031"),
    ]
    y = 95
    for label, value in rows:
        d.text((320, y), label, font=lab, fill=(60, 60, 60))
        d.text((320, y + 22), value, font=val, fill="black")
        y += 72
    d.text((30, 590), "SPÉCIMEN — PERSONNE FICTIVE", font=font(BOLD, 22), fill=(200, 0, 0))
    return img


def cni_verso():
    img, d = card()
    d.text((20, 18), "CEDEAO / ECOWAS", font=font(BOLD, 28), fill="white")
    d.text((40, 110), "NIN", font=font(SANS, 20), fill=(60, 60, 60))
    d.text((40, 134), "2 750 1985 01234", font=font(BOLD, 28), fill="black")
    d.text((40, 200), "Région : DAKAR    Département : DAKAR", font=font(SANS, 22), fill="black")
    d.text((40, 240), "SPÉCIMEN — PERSONNE FICTIVE", font=font(BOLD, 22), fill=(200, 0, 0))
    mrz = td1("ID", "SEN", "20119850512001237", "850512", "F", "310311", "SEN", "NDIAYE", "AWA")
    d.rectangle([0, 430, 1011, 638], fill="white")
    for i, line in enumerate(mrz):
        d.text((40, 450 + i * 58), line, font=font(MONO, 44), fill="black")
    return img, mrz


def passeport():
    img = Image.new("RGB", (1250, 880), (238, 236, 246))
    d = ImageDraw.Draw(img)
    d.text((30, 20), "RÉPUBLIQUE DU SÉNÉGAL  —  PASSEPORT / PASSPORT", font=font(BOLD, 30), fill=(20, 40, 90))
    d.rectangle([40, 90, 330, 470], fill=(200, 200, 200))
    lab, val = font(SANS, 20), font(BOLD, 28)
    rows = [("Nom / Surname", "DIOP"), ("Prénoms / Given names", "MOUSSA IBRAHIMA"), ("Date de naissance / Date of birth", "03 JAN 1990"),
            ("Sexe / Sex", "M"), ("N° du passeport / Passport No", "A01234567"), ("Date d'expiration / Date of expiry", "14 FEB 2029")]
    y = 90
    for label, value in rows:
        d.text((370, y), label, font=lab, fill=(60, 60, 60))
        d.text((370, y + 24), value, font=val, fill="black")
        y += 68
    d.text((40, 520), "SPÉCIMEN — PERSONNE FICTIVE", font=font(BOLD, 24), fill=(200, 0, 0))
    mrz = td3("SEN", "A01234567", "900103", "M", "290214", "SEN", "DIOP", "MOUSSA IBRAHIMA")
    d.rectangle([0, 700, 1250, 880], fill="white")
    for i, line in enumerate(mrz):
        d.text((25, 715 + i * 70), line, font=font(MONO, 45), fill="black")
    return img, mrz


def photo_inclinee(img):
    random.seed(4)
    bg = Image.new("RGB", (1400, 1000), (120, 100, 80))
    rotated = img.rotate(6, expand=True, fillcolor=(120, 100, 80))
    bg.paste(rotated, (160, 150))
    bg = bg.filter(ImageFilter.GaussianBlur(1.1))
    px = bg.load()
    for _ in range(40000):
        x, y = random.randrange(bg.width), random.randrange(bg.height)
        r, g, b = px[x, y]
        n = random.randint(-35, 35)
        px[x, y] = (max(0, min(255, r + n)), max(0, min(255, g + n)), max(0, min(255, b + n)))
    return bg


if __name__ == "__main__":
    os.makedirs(OUT, exist_ok=True)
    recto = cni_recto()
    recto.save(os.path.join(OUT, "cni_recto.jpg"), quality=90)
    verso, mrz1 = cni_verso()
    verso.save(os.path.join(OUT, "cni_verso.jpg"), quality=90)
    pp, mrz3 = passeport()
    pp.save(os.path.join(OUT, "passeport.jpg"), quality=90)
    photo_inclinee(recto).save(os.path.join(OUT, "cni_photo_inclinee.jpg"), quality=80)
    photo_inclinee(recto).rotate(90, expand=True).save(os.path.join(OUT, "cni_photo_tournee.jpg"), quality=80)
    print("MRZ CNI :", *mrz1, sep="\n  ")
    print("MRZ passeport :", *mrz3, sep="\n  ")
