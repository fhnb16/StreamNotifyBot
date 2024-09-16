<!DOCTYPE html>
<html class="" lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Notifications</title>
    <link rel='stylesheet' type='text/css' href='https://dev.fhnb.ru/assets/?page=view&dir=tailwind.css/2.2.19&name=tailwind.min.css'>
    <!--<script type='text/javascript' src='https://dev.fhnb.ru/assets/?page=view&dir=tailwind.css/3.4.5&name=tailwind.js'></script>/-->
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        /* Темная тема для body */
        body.dark {
            background-color: #1a202c; /* Лунно-ночной фон */
            color: #e2e8f0!important; /* Светлый текст */
        }

        /* Темная тема для select */
        body.dark #channelSelect,
        body.dark #notifyLevelSelect {
            background-color: #2d3748;
            color: #e2e8f0;
        }

        /* Темная тема для таблицы */
        body.dark #notifyTable {
            background-color: #2d3748;
            color: #e2e8f0;
        }

        /* Темная тема для заголовков таблицы */
        body.dark #notifyTable thead {
            background-color: #4a5568;
        }

        /* Темная тема для кнопок */
        /*body.dark button {
            background-color: #4a5568;
            color: #e2e8f0;
        }*/
        button {
            margin: 0 auto;
            width: 100%;
        }
    </style>
</head>
<body class="text-gray-800">
    <div class="container mx-auto p-4">
        <h1 id="pageTitle" class="text-2xl font-bold mb-4">Edit Notifications</h1>
        
        <!-- Список каналов -->
        <select id="channelSelect" class="p-2 border bg-gray-200 border-gray-300 rounded-md w-full hidden">
            <option value="">Select a channel</option>
        </select>
        
        <!-- Таблица уведомлений -->
        <div id="notifyTableContainer" class="mt-6">
            <table id="notifyTable" class="w-full block overflow-auto bg-white shadow-md rounded-md hidden">
                <thead class="bg-gray-800 text-white">
                    <tr>
                        <th id="tableName" class="p-2">Broadcaster Name</th>
                        <th id="tableId" class="p-2">Subscriber ID</th>
                        <th id="tableNote" class="p-2">Note</th>
                        <th id="tableType" class="p-2">Notification Type</th>
                        <th id="tableActions" class="p-2">Actions</th>
                    </tr>
                </thead>
                <tbody id="notifyTableBody"></tbody>
            </table>
        </div>
        
        <!-- Кнопка добавления -->
        <button id="addNotify" class="bg-blue-500 text-white py-2 px-4 rounded mt-4 hidden">Add Notify</button>

        <!-- Список каналов -->
        <select id="notifyLevelSelect" class="p-2 border bg-gray-200 border-gray-300 rounded-md w-full hidden">
            <option id="selectNotifyType_online" value="online">Livestream start</option>
            <option id="selectNotifyType_live" value="live">Online and Offline</option>
            <option id="selectNotifyType_updates" value="updates">Updates and Offline</option>
            <option id="selectNotifyType_all" value="all">All status changes</option>
        </select>
        
        <!-- Кнопка добавления -->
        <button id="subscribeNotify" class="bg-blue-500 text-white py-2 px-4 rounded mt-4 hidden">Subscribe to notifications</button>
        
        <!-- Кнопка сохранения -->
        <button id="addNewButton" class="bg-green-500 text-white py-2 px-4 rounded mt-4 hidden">Add new notification</button>
    </div>

    <script>
        const strings = {
            "ru" : {
                "title": "Настроить уведомления",
                "addNotify": "Добавить уведомление о стримере",
                "tableName": "Стример",
                "tableId": "ID",
                "tableNote": "Заметка",
                "tableType": "Тип",
                "tableActions": "Действия",
                "actionsUnsubscribe": "Отписаться",
                "actionsDelete": "Удалить",
                "actionsEdit": "Изм.",
                "selectChannel": "Выберите канал",
                "selectNotifyType_online": "Начало трансляции",
                "selectNotifyType_live": "Онлайн и Оффлайн статус",
                "selectNotifyType_updates": "Обновления и завершение трансляции",
                "selectNotifyType_all": "Все изменения статуса",
                "addNewButton": "Добавить уведомление для",
            },
            "en" : {
                "title": "Edit Notifications",
                "addNotify": "Subscribe to notify about streamer",
                "tableName": "Streamer",
                "tableId": "ID",
                "tableNote": "Note",
                "tableType": "Type",
                "tableActions": "Actions",
                "actionsUnsubscribe": "Unsubscribe",
                "actionsDelete": "Delete",
                "actionsEdit": "Edit",
                "selectChannel": "Select a channel",
                "selectNotifyType_online": "Livestream start",
                "selectNotifyType_live": "Online and Offline",
                "selectNotifyType_updates": "Updates and Offline",
                "selectNotifyType_all": "All status changes",
                "addNewButton": "Add new notification for",
            },
        }
        
        const tg = window.Telegram.WebApp;
        //console.log(tg);
        const userId = tg.initDataUnsafe.user.id;
        const userData = tg.initDataUnsafe.user;
        const userLang = tg.initDataUnsafe.user.language_code;

        var locale = (userLang == "ru" || userLang == "kk" || userLang == "uk" || userLang == "be") ? "ru" : "en";

        document.getElementById('pageTitle').innerText = strings[locale].title;
        document.getElementById('addNotify').innerText = strings[locale].addNotify;
        document.getElementById('tableName').innerText = strings[locale].tableName;
        document.getElementById('tableId').innerText = strings[locale].tableId;
        document.getElementById('tableNote').innerText = strings[locale].tableNote;
        document.getElementById('tableType').innerText = strings[locale].tableType;
        document.getElementById('tableActions').innerText = strings[locale].tableActions;
        document.getElementById('selectNotifyType_online').innerText = strings[locale].selectNotifyType_online;
        document.getElementById('selectNotifyType_all').innerText = strings[locale].selectNotifyType_all;
        document.getElementById('selectNotifyType_live').innerText = strings[locale].selectNotifyType_live;
        document.getElementById('selectNotifyType_updates').innerText = strings[locale].selectNotifyType_updates;
        document.getElementById('addNewButton').innerText = strings[locale].addNewButton;

        let isAdmin = false; // Переменная для проверки, является ли пользователь администратором
        const notifyTableBody = document.getElementById('notifyTableBody');
        const addNewButton = document.getElementById('addNewButton');
        const addNotifyButton = document.getElementById('addNotify');
        const subNotifyButton = document.getElementById('subscribeNotify');
        const notifyLevelSelect = document.getElementById('notifyLevelSelect');

        const colorScheme = tg.colorScheme;

        // Если включена темная тема, добавляем класс "dark" к тегу <html>
        if (colorScheme == "dark") {
            document.body.classList.add('dark');
        }

        /*Telegram.WebApp.onEvent('mainButtonClicked', function(){
            window.location.href = "http://localhost:3000";
        });*/

        // Отправляем POST-запрос на сервер для проверки ID пользователя
        async function checkUser() {
            const response = await fetch('check_user.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ user_id: userId })
            });

            const data = await response.json();
            if (data.status === 'ok') {
                isAdmin = data.is_admin;
                if(isAdmin) {
                    tg.expand();
                    //tg.MainButton.setText("Open dev page");
                    //tg.MainButton.show();
                }
                loadChannels();
            } else {
                tg.HapticFeedback.notificationOccurred('warning');
                document.getElementById('pageTitle').textContent = 'Access denied';
            }
        }

        // Загружаем список каналов для администратора или его подписки для обычного пользователя
        async function loadChannels(justList = false) {
            notifyTableBody.innerHTML = ''; // Очищаем таблицу
            const channelSelect = document.getElementById('channelSelect');
            addNewButton.classList.add('hidden');
            const response = await fetch('load_channels.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ user_id: userId, is_admin: isAdmin, sender_id: userId, justList: justList })
            });

            const data = await response.json();
            if(justList) {
                notifyTableBody.innerHTML = ''; // Очищаем таблицу
                channelSelect.innerHTML = `<option value="">${strings[locale].selectChannel}</option>`; // Очищаем список
                renderChannelSelect(data.channels, justList);
                return;
            }
            if (isAdmin) {
                channelSelect.innerHTML = `<option value="">${strings[locale].selectChannel}</option>`; // Очищаем список
                renderChannelSelect(data.channels);
                var urlParams = new URLSearchParams(window.location.search);
                var broadcaster = urlParams.get('broadcaster_id');
                if (broadcaster) {
                    var options = channelSelect.options;
                    for (let i = 0; i < options.length; i++) {
                        if (options[i].value === broadcaster) {
                            options[i].selected = true;
                            channelSelect.dispatchEvent(new Event('change'));
                            break;
                        }
                    }
                }
                tableName.classList.add('hidden');
            } else {
                notifyTableBody.innerHTML = ''; // Очищаем таблицу
                Object.entries(data.channels).forEach(([key, channel]) => {
                    renderNotifyTable(channel.notify, channel); // Если пользователь, просто отображаем его подписки
                });
                tg.HapticFeedback.impactOccurred('soft');
                addNotifyButton.classList.remove('hidden');
                tableId.classList.add('hidden');
                tableNote.classList.add('hidden');
            }
        }

        // Функция рендеринга списка каналов (для администратора)
        function renderChannelSelect(channels, justList = false) {
            const channelSelect = document.getElementById('channelSelect');
            channelSelect.classList.remove('hidden');
            Object.entries(channels).forEach(([key, channel]) => {
                //console.log('item: ' + channel.name);
                const option = document.createElement('option');
                option.value = channel.broadcaster_id;
                option.textContent = channel.name;
                channelSelect.appendChild(option);
            });

            channelSelect.addEventListener('change', function() {
                const selectedChannelId = this.value;
                const notifyTable = document.getElementById('notifyTable');
                if (selectedChannelId) {
                    tg.HapticFeedback.selectionChanged();
                    if(justList) {
                        subNotifyButton.classList.remove('hidden');
                        notifyLevelSelect.classList.remove('hidden');
                        addNewButton.classList.add('hidden');
                        subNotifyButton.dataset.bid = selectedChannelId;
                        notifyTableBody.innerHTML = ''; // Очищаем таблицу
                        notifyTable.classList.add("hidden");
                        return;
                    }
                    notifyTableBody.innerHTML = ''; // Очищаем таблицу
                    notifyTable.classList.remove("hidden");
                    const selectedChannel = Object.entries(channels).find(([key, channel]) => channel.broadcaster_id === selectedChannelId)[1];
                    renderNotifyTable(selectedChannel.notify, selectedChannel);
                    addNewButton.classList.remove('hidden');
                    addNewButton.innerText = strings[locale].addNewButton + " " + selectedChannel.name;
                    addNewButton.dataset.bid = selectedChannelId;
                    const url = new URL(window.location.href);
                    url.searchParams.set('broadcaster_id', selectedChannelId);
                    window.history.pushState(null, '', url.toString());
                } else {
                    subNotifyButton.classList.add('hidden');
                    notifyLevelSelect.classList.add('hidden');
                    addNewButton.classList.add('hidden');
                    notifyTableBody.innerHTML = ''; // Очищаем таблицу
                    notifyTable.classList.add("hidden");
                    const url = new URL(window.location.href);
                    url.searchParams.set('broadcaster_id', "");
                    window.history.pushState(null, '', url.toString());
                }
            });
        }

        // Функция рендеринга таблицы уведомлений
        function renderNotifyTable(notify, channel = null) {
            const notifyTable = document.getElementById('notifyTable');
            notifyTable.classList.remove('hidden');
            Object.entries(notify).forEach(([key, value]) => {
                const row = document.createElement('tr');
                //var splitted = splitOnce(key, ':');
                var nameFormatted = value.about;
                var notifyFormatted = value.type;
                if(!isAdmin){
                    var notesObject = stringToObject(value.about);
                    nameFormatted = notesObject.first_name + " " + notesObject.last_name;
                    if(notesObject.username != undefined){
                        nameFormatted += ", " + notesObject.username;
                    }
                    notifyFormatted = strings[locale]['selectNotifyType_'+value.type];
                }else{
                    addNotifyButton.classList.add('hidden');
                }
                row.innerHTML = `
                    ${!isAdmin ? `<td class="border p-2">${channel.name}</td>` : ``}
                    ${isAdmin ? `<td class="border p-2">${key}</td>` : ``}
                    ${isAdmin ? `<td class="border p-2">${nameFormatted}</td>` : ``}
                    <td class="border p-2">${notifyFormatted}</td>
                    <td class="border p-2 table-cell flex-wrap">
                        ${isAdmin ? `<button class="bg-yellow-500 text-white p-1 my-1 rounded editBtn flex-grow" data-bid="${channel.broadcaster_id}" data-sid="${key}" data-unotify="${value.about}" data-unvalue="${value.type}">${strings[locale].actionsEdit}</button>
                        <button class="bg-red-500 text-white p-1 my-1 rounded deleteBtn flex-grow" data-bid="${channel.broadcaster_id}" data-sid="${key}" data-unotify="${value.about}">${strings[locale].actionsDelete}</button>` : 
                        `<button class="bg-red-500 text-white p-1 my-1 rounded unsubscribeBtn flex-grow" data-bid="${channel.broadcaster_id}" data-bname="${channel.name}">${strings[locale].actionsUnsubscribe}</button>`}
                    </td>
                `;
                notifyTableBody.appendChild(row);
            });
            //addNewButton.classList.remove('hidden');
            //addNewButton.disabled = false; // Активируем кнопку сохранения
        }

        // Обработка нажатия кнопки добавления подписки (для обычного пользователя)
        addNotifyButton.addEventListener('click', function(e) {
            //if (e.target.classList.contains('unsubscribeBtn'))
            addNotifyButton.classList.add('hidden');
            notifyTable.classList.add("hidden");
            loadChannels(true);
        });

        // Обработка нажатия кнопки подтверждения подписки (для обычного пользователя)
        subNotifyButton.addEventListener('click', function(e) {
            addNotifyButton.classList.remove('hidden');
            channelSelect.classList.add('hidden');
            subNotifyButton.classList.add('hidden');
            notifyLevelSelect.classList.add('hidden');
            const bid = e.target.dataset.bid;
            var subData = Object.entries(userData).filter(([key, value]) => key != "allows_write_to_pm").map(function([key, value]){ // .filter(([key, value]) => value != userId)
                if(key == "username"){
                    return key + " - @" + value;
                }else{
                    if(key != "id"){
                        return key + " - " + value;
                    } else {
                        return key + " - tg://user?id=" + value;
                    }
                }
            }).join(",");
            /*var notifySubArray = {
                "broadcaster_id": e.target.dataset.bid,
                "subscriber_id": userId,
                "subscriber_data": subData
            }
            console.log(notifySubArray);*/
            subscribeNotify(bid, subData, notifyLevelSelect.value);
            loadChannels();
        });

        // Обработка нажатия кнопки добавления подписки на уведомления (вручную, например на канал с id -100)
        addNewButton.addEventListener('click', function(e) {
            addNotifyButton.classList.remove('hidden');
            channelSelect.classList.add('hidden');
            subNotifyButton.classList.add('hidden');
            notifyLevelSelect.classList.add('hidden');
            const bid = e.target.dataset.bid;
            var newID = prompt("Add subscribe ID", ""); 
            var newDescr = prompt("Add subscribe info", ""); 
            var newLevel = prompt("Add notify level\n\nonline - notify about livestream start\nlive - notify about online and offline\nupdates - notify about updates and offline\nall - notify about all status changes", ""); 
            /*var notifySubArray = {
                "broadcaster_id": e.target.dataset.bid,
                "subscriber_id": userId,
                "subscriber_data": subData
            }
            console.log(notifySubArray);*/
            if(newID != "" && newDescr != "" && newLevel != ""){
                    //subscribe(bid, newDescr, newValue, sid);
                    subscribeNotify(bid, newDescr, newLevel, newID);
                    //addNewButton.classList.add('hidden');
                    loadChannels();
                    //row.remove();
                }
        });

        // Обработка нажатия кнопки для отписки (для обычного пользователя)
        notifyTableBody.addEventListener('click', function(e) {
            if (e.target.classList.contains('unsubscribeBtn') && confirm("Are you sure want to unsubscribe from: " + e.target.dataset.bname)) {
                const row = e.target.closest('tr');
                const bid = e.target.dataset.bid;
                unsubscribeNotify(bid);
                row.remove();
            }
            if (e.target.classList.contains('deleteBtn') && confirm("Are you sure want to delete notification for: " + e.target.dataset.unotify)) {
                const row = e.target.closest('tr');
                const bid = e.target.dataset.bid;
                const sid = e.target.dataset.sid;
                removeNotify(bid, sid);
                row.remove();
            }
            if (e.target.classList.contains('editBtn')) {
                const row = e.target.closest('tr');
                const bid = e.target.dataset.bid;
                const userNotifyDescr = e.target.dataset.unotify;
                const userNotifyValue = e.target.dataset.unvalue;
                const sid = e.target.dataset.sid;
                var newDescr = prompt("Edit subscribe info", userNotifyDescr); 
                var newValue = prompt("Edit notify level\n\nonline - notify about livestream start\nlive - notify about online and offline\nupdates - notify about updates and offline\nall - notify about all status changes", userNotifyValue); 
                if(newDescr != "" && newValue != ""){
                    editNotify(bid, newDescr, newValue, sid);
                    loadChannels();
                    //row.remove();
                }
            }
        });

        // Функция добавления нотификейшена
        async function subscribeNotify(broadcasterId, userData, subLevel, subscriber_id = null) {
            var uid = userId;
            if(subscriber_id != null) uid = subscriber_id;
            await fetch('subscribe.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ user_id: uid, broadcaster_id: broadcasterId, user_data: userData, sub_level: subLevel, is_admin: isAdmin, sender_id: userId})
            })
            .then(response => response.json())
            .then(data => {
                if(data.status == "ok"){
                    tg.HapticFeedback.notificationOccurred('success');
                    const url = new URL(window.location.href);
                    url.searchParams.set('broadcaster_id', broadcasterId);
                    window.location.href = url.toString();
                } else {
                    // Обработка ошибки: например, вывести сообщение об ошибке
                    tg.showAlert(data.status+'\n\n'+data.result);
                    console.error('Запрос не удался:', data.result);
                    tg.HapticFeedback.notificationOccurred('error');
                }
            });
        }

        // Функция изменения нотификейшена
        async function editNotify(broadcasterId, userData, subLevel, subscriber_id = null) {
            var uid = userId;
            if(subscriber_id != null) uid = subscriber_id;
            await fetch('subscribe.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ user_id: uid, broadcaster_id: broadcasterId, user_data: userData, sub_level: subLevel, is_admin: isAdmin, sender_id: userId, sub_edit: true })
            })
            .then(response => response.json())
            .then(data => {
                if(data.status == "ok"){
                    tg.HapticFeedback.notificationOccurred('success');
                    const url = new URL(window.location.href);
                    url.searchParams.set('broadcaster_id', broadcasterId);
                    window.location.href = url.toString();
                } else {
                    // Обработка ошибки: например, вывести сообщение об ошибке
                    tg.showAlert(data.status+'\n\n'+data.result);
                    console.error('Запрос не удался:', data);
                    tg.HapticFeedback.notificationOccurred('error');
                }
            });
        }

        // Функция отписки
        async function unsubscribeNotify(broadcasterId) {
            await fetch('unsubscribe.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ user_id: userId, broadcaster_id: broadcasterId })
            })
            .then(response => response.json())
            .then(data => {
                if(data.status == "ok"){
                    tg.HapticFeedback.notificationOccurred('success');
                    const url = new URL(window.location.href);
                    url.searchParams.set('broadcaster_id', broadcasterId);
                    window.location.href = url.toString();
                } else {
                    // Обработка ошибки: например, вывести сообщение об ошибке
                    console.error('Запрос не удался:', data);
                    tg.showAlert(data.status+'\n\n'+data.result);
                    tg.HapticFeedback.notificationOccurred('error');
                }
            });
        }

        // Функция удаления оповещения
        async function removeNotify(broadcasterId, subscriberId) {
            await fetch('remove.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ user_id: userId, broadcaster_id: broadcasterId, subscriber_id: subscriberId, is_admin: isAdmin, sender_id: userId })
            })
            .then(response => response.json())
            .then(data => {
                if(data.status == "ok"){
                    tg.HapticFeedback.notificationOccurred('success');
                    const url = new URL(window.location.href);
                    url.searchParams.set('broadcaster_id', broadcasterId);
                    window.location.href = url.toString();
                } else {
                    // Обработка ошибки: например, вывести сообщение об ошибке
                    console.error('Запрос не удался:', data);
                    tg.showAlert(data.status+'\n\n'+data.result);
                    tg.HapticFeedback.notificationOccurred('error');
                }
            });
        }

        function splitOnce(str, separator) {
        const index = str.indexOf(separator);
        if (index !== -1) {
            return [str.slice(0, index), str.slice(index + 1)];
        } else {
            return [str];
        }
        }

        function stringToObject(str) {
        // Разбиваем строку по запятым на пары ключ-значение
        const pairs = str.split(',');

        // Создаем пустой объект для результата
        const result = {};

        // Перебираем каждую пару
        pairs.forEach(pair => {
            // Разделяем пару на ключ и значение по дефису
            const [key, value] = pair.split(' - ');
            // Добавляем пару в объект
            result[key.trim()] = value.trim();
        });

        return result;
        }

        checkUser(); // Запуск проверки пользователя при загрузке страницы
    </script>
</body>
</html>
