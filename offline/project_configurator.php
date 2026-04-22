<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Projekt anlegen - Cube Kingdom</title>
    <link rel="stylesheet" href="css/style.css" />
</head>
<body>

<p><a href="my_projects.php">Meine Projekte ansehen</a></p>

<h1>Eigenes Projekt anlegen</h1>

<form id="project-form" method="post" action="save_project.php">
    <label for="projectName">Projektname:</label>
    <input type="text" id="projectName" name="projectName" required />

    <br/><br/>

    <label for="serverType">Serverart:</label>
    <select id="serverType" name="serverType">
        <option value="purpur">Purpur</option>
        <option value="vanilla">Vanilla</option>
        <option value="forge">Forge</option>
        <option value="fabric">Fabric</option>
        <option value="paper">Paper</option>
    </select>

    <br/><br/>

    <label for="ram">RAM (GB):</label>
    <select id="ram" name="ram">
        <option value="1">1 GB</option>
        <option value="2">2 GB</option>
        <option value="4">4 GB</option>
        <option value="8">8 GB</option>
        <option value="10">10 GB</option>
    </select>

    <br/><br/>

    <p>Monatlicher Preis: <span id="price">0.00</span> €</p>

    <!-- Hidden price field -->
    <input type="hidden" id="priceField" name="price" value="0.00" />

    <button type="submit">Projekt anlegen</button>
</form>

<script>
const basePrices = {
    purpur: 2.00,
    vanilla: 1.50,
    forge: 3.00,
    fabric: 2.50,
    paper: 2.00
};

const ramMultiplier = 1.00;

function updatePrice() {
    const serverType = document.getElementById('serverType').value;
    const ram = parseInt(document.getElementById('ram').value);

    let basePrice = basePrices[serverType];
    let totalPrice = basePrice + (ram * ramMultiplier);

    document.getElementById('price').innerText = totalPrice.toFixed(2);
    document.getElementById('priceField').value = totalPrice.toFixed(2);
}

document.getElementById('serverType').addEventListener('change', updatePrice);
document.getElementById('ram').addEventListener('change', updatePrice);

updatePrice();
</script>

</body>
</html>
