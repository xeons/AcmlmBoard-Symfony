# Licensing

## This port

The Symfony port - everything under `src/`, `templates/`, `tests/`, `config/`,
`migrations/`, `public/css/`, `public/js/`, and the documentation - is

> Copyright (C) 2026 Xeon Productions
>
> This program is free software: you can redistribute it and/or modify it under the
> terms of the GNU Lesser General Public License as published by the Free Software
> Foundation, either version 3 of the License, or (at your option) any later version.
>
> This program is distributed in the hope that it will be useful, but WITHOUT ANY
> WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
> PARTICULAR PURPOSE. See the GNU Lesser General Public License for more details.
>
> You should have received a copy of the GNU Lesser General Public License along with
> this program. If not, see <https://www.gnu.org/licenses/>.

The full texts are in [`COPYING.LESSER`](COPYING.LESSER) (LGPL-3.0) and
[`COPYING`](COPYING) (GPL-3.0). The LGPL is written as a set of additional permissions
on top of the GPL, so both are needed to read it.

Developed with **Claude Code**. That is a disclosure of the tooling, not a claim of
authorship: an AI system cannot hold or transfer copyright, and Anthropic asserts no
ownership of what it produces. The rights in this port are Xeon Productions'.

---

## What this licence does *not* cover

**AcmlmBoard 1.A3 itself was never released under any licence.** Its `credits.php`
says only:

> AcmlmBoard 1.A3 is copyright 2000-2005 with contributions by the following: Acmlm,
> Emuz, Blades, Jesper, ErkDog, ||bass, Kasumi

There is no `LICENSE` file, no `COPYING`, and no permission grant anywhere in the
1.A3 or 1.92.08 trees - only a reservation of copyright. The same is true of the
artwork and data those distributions shipped.

This has two consequences worth being clear about:

1. **The LGPL above applies to the port's own code**, which is newly written. It is
   not, and cannot be, a relicensing of AcmlmBoard - nobody can put a licence on
   somebody else's copyrighted work without their permission.

2. **Bundled assets from the original distribution are not covered by it.** That
   includes everything under `public/images/` - the title banners, the 292-picture
   avatar gallery, the rank and smiley graphics, the scheme background images and
   `public/images/rpg/font.png` - along with `config/smileys.json` and
   `config/avatars.json`, which are transcriptions of the original board's data. Those
   remain the property of their authors.

The port reproduces the original's *behaviour* - its EXP curve, its rank ladders, its
layout-token vocabulary - and reimplements it from scratch rather than copying its
code. Where the line falls between an unprotectable idea and a derivative work is a
legal question, not a technical one, and this file does not pretend to answer it.

**If you intend to distribute this publicly**, the clean paths are to obtain
permission from the AcmlmBoard authors, or to replace the bundled assets with your own
and ship only the code. Neither is something a README can do for you.

---

## Dependencies

All 108 runtime dependencies are under permissive licences - 105 MIT, two BSD-2-Clause
and one BSD-3-Clause (Twig) - which are compatible with distribution under the LGPL.
`composer licenses --no-dev` lists them.
