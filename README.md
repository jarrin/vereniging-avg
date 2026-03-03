# AVG Vragenlijsten voor Verenigingen

Dit project is ontworpen om verenigingen te helpen bij het jaarlijks verkrijgen van toestemming van leden en vrijwilligers conform de AVG-wetgeving.

## Mappenstructuur Overzicht

- **`app/`**: Bevat de kernlogica van de applicatie (PHP-code).
  - `Controllers/`: Verwerkt interacties tussen de gebruiker en de applicatie.
  - `Models/`: Interactie met de database.
  - `Services/`: Specifieke logica voor e-mail, Excel-verwerking, etc.
- **`public/`**: De enige map die via de browser toegankelijk is. Hier staat `index.php`.
- **`resources/`**: Bevat de 'bronbestanden' (HTML templates, CSS, JS).
- **`storage/`**: Tijdelijke opslag voor logo's, logs en exports.
- **`database/`**: SQL-scripts en schema-definities.
- **`.bin/`**: Scripts voor taken op de achtergrond (zoals het versturen van e-mails in batches).

## Installatie

1. Kloon het project naar de `htdocs` map van XAMPP.
2. Kopieer `.env.example` naar `.env` en configureer de database en mail-instellingen.
3. Run `composer install` om de benodigde pakketten te installeren.
4. Zorg dat de map `storage/` schrijfrechten heeft voor de webserver.

## Belangrijke Functies

- **Batch Mailing**: Om te voorkomen dat de server als spam wordt gemarkeerd, worden mails in kleine groepen verstuurd.
- **Data Privacy**: Gegevens worden na de rapportageperiode definitief verwijderd.
- **Beveiligde Links**: Leden ontvangen een unieke link die slechts één keer bruikbaar is.
