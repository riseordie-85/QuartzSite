---
name: QuartzInk
tagline: Quartz-style firmware for e-readers.
summary: Firmware for the Xteink X3, X4 Pro and Boox Picco with the Quartz look, built on CrossPoint.
status: released
stone: clear
order: 6
platforms: [e-reader]
repo: riseordie-85/QuartzInk
download: firmware
showcases:
  - dir: /showcase/quartzink
    intro: true
    slides:
      - title: Shelf-based home screen
        image: home.png
        alt: The QuartzInk home screen with the current book, recent covers and genre shelves.
        text: |
          A redesigned home screen built around shelves.

          Your books are sorted by the folders in your books folder: /Books/Fantasy/ becomes a Fantasy shelf on your home screen.

          The book you're reading stays up front, with how far along you are and how long you've read it.

          Your reading stats, settings, files, full library and connections are one tap away.
      - title: Reader menu
        image: reader-menu.png
        alt: A book page with the reader menu open, showing home, search, contents, bookmark and page settings buttons and a page slider.
        text: |
          Open the menu while reading and everything you need is in one place: home, search, contents, bookmarks and page settings.

          The slider along the bottom jumps to any page in the chapter, and shows how far through the book you are.
      - title: Page settings
        image: page-settings.png
        alt: The page settings panel with size, font, spacing, margins and alignment options.
        text: |
          Quartz-style page settings: text size, font, line spacing, margins and alignment, each a single tap.

          Choose NV Jost, Libron or a font you've added yourself, then keep the look for this book or apply it to all of them.

          Advanced has the finer controls, like hyphenation and how images are shown.
      - title: Settings
        image: settings.png
        alt: The settings menu with System, Display, Wallpapers, Reader, Controls, Library and Connectivity.
        text: |
          Settings, sorted into seven clear groups: System, Display, Wallpapers, Reader, Controls, Library and Connectivity.

          Controls lets you choose what each button does, down to taking a screenshot with a click of the power button.
      - title: Wallpapers and overlays
        image: wallpapers.png
        alt: The Wallpapers screen on its Overlays tab, with a pinned overlay and a list of images.
        text: |
          Pick your sleep screen right on the reader.

          Wallpapers fill the screen. Overlays sit on top of the page you were reading or your book's cover.

          Pin a favorite or shuffle through them, and add more from the reader's web dashboard or the Extras gallery on this site.
      - title: Reading stats
        image: stats.png
        alt: Reading stats showing time read today, this week and this year, a streak, a calendar of reading days and totals.
        text: |
          Reading stats that keep themselves.

          See your time today, this week and this year, your streak, a calendar of the days you've read, and totals for time, pages, sessions and finished books.
      - title: Your reading habits
        image: stats-more.png
        alt: More reading stats showing books finished by month, reading time by time of day, and recently finished books.
        text: |
          Books finished month by month, the time of day you read most, and the books you've finished lately.

          Export your stats from the web dashboard and bring them into Quartz Reader on your phone.
  - dir: /showcase/quartzink-web
    kind: phone
    title: The web dashboard
    slides:
      - title: Your reader, from your phone
        image: home.webp
        alt: The web dashboard home page with File Browser, Books, Wallpapers, Fonts, Stats and Extras tiles.
        text: |
          Open File Transfer on the reader, then go to quartzink.local, or the address it shows, on your phone or computer.

          Everything is one tap away: your files and books, wallpapers, fonts, reading stats and the Extras gallery.
      - title: Settings
        image: settings.webp
        alt: The web settings page with Display, Reader and Controls sections.
        text: |
          Change the reader's settings from a full-size screen, grouped the same way as on the reader: Display, Reader, Controls and more.

          Make your changes, press Save Settings, and they're sent to the reader.
      - title: Reading stats
        image: stats.webp
        alt: The web reading stats page with totals, a reading days calendar, when you read and books.
        text: |
          See your reading stats in full: totals and streaks, a calendar of the days you've read, the time of day and day of the week you read most, and time spent on each book.

          Export them as JSON, or import them into Quartz Reader on your phone.
      - title: Wallpapers
        image: wallpapers.webp
        alt: The web wallpapers page with the sleep screen choice, an upload area and the reader's images.
        text: |
          Choose your sleep screen, then drop in photos and PNGs.

          Photos are resized for the reader's screen and saved as grayscale wallpapers. PNGs keep their transparency and become overlays.

          Pin, use or delete any image, or shuffle them.
      - title: Fonts
        image: fonts.webp
        alt: The web fonts page listing installed font families, with an upload form.
        text: |
          Upload a font family's folder, or a whole folder of families at once, and they're ready on the reader.

          See what's installed and remove what you don't use.
      - title: Extras
        image: extras.webp
        alt: The Extras gallery inside the web dashboard, showing overlays with Install and Use buttons.
        text: |
          Browse the Quartz gallery of wallpapers, overlays and fonts without leaving the dashboard.

          Install sends each one straight to your reader, sized for its screen, and anything you already have is ready to use.
---

Firmware for the Xteink X3, X4 Pro and Boox Picco, built on CrossPoint. It adds a home screen with shelves, Quartz-style page settings, sleep wallpapers and overlays, and reading stats you can export.
