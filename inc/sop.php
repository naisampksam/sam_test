<?php
// How to (SOP): short steps for every feature, in English and Malayalam (help.php shows and searches it).
// Each step is "short line :: explanation" — the explanation shows when the step is hovered (computer) or tapped (phone).
// Keep it simple: at most 5 steps per topic. Button names stay in English — that is what is on the screen.
// who: 'all' everyone · 'orders' orders / production team (and admin) · 'admin' admin only · 'money' bills & stock.
declare(strict_types=1);

const SOP_CATS = [
    'start' => ['Getting started', 'തുടക്കം'],
    'orders' => ['Orders', 'ഓർഡറുകൾ'],
    'production' => ['Printing & shipping', 'പ്രിന്റിംഗ് & ഷിപ്പിംഗ്'],
    'designs' => ['Saved designs', 'ഡിസൈനുകൾ'],
    'money' => ['Bills & money', 'ബില്ലും പണവും'],
    'stock' => ['Stock', 'സ്റ്റോക്ക്'],
    'tasks' => ['Tasks', 'ടാസ്കുകൾ'],
    'attendance' => ['Attendance', 'അറ്റൻഡൻസ്'],
    'admin' => ['Admin', 'അഡ്മിൻ'],
];

/** "What's in the app": [icon, English name, Malayalam name, English line, Malayalam line, topic id]. */
function sop_options(): array
{
    return [
        ['➕', 'New order', 'പുതിയ ഓർഡർ', 'Make an order with items, prints and address.', 'ഐറ്റം, പ്രിന്റ്, അഡ്രസ് സഹിതം ഓർഡർ.', 'create-order'],
        ['📋', 'Orders', 'ഓർഡറുകൾ', 'All orders by stage; search and filter.', 'എല്ലാ ഓർഡറും ഘട്ടം തിരിച്ച്; സെർച്ച്, ഫിൽട്ടർ.', 'find-order'],
        ['🖨', 'Print list', 'പ്രിന്റ് ലിസ്റ്റ്', 'What to print and which blanks to pick.', 'എന്ത് പ്രിന്റ് ചെയ്യണം, ഏത് ബ്ലാങ്ക് എടുക്കണം.', 'print-list'],
        ['✅', 'Ticks', 'ടിക്കുകൾ', 'Print processed → Printed → Packed → Shipped.', 'Print processed → Printed → Packed → Shipped.', 'ticks'],
        ['🧵', 'Received', 'Received', 'Embroidery / HD / Puff / Screen came back.', 'എംബ്രോയ്ഡറി / HD / Puff / Screen തിരികെ വന്നു.', 'received'],
        ['🏷', 'Shipping labels', 'ഷിപ്പിംഗ് ലേബൽ', 'Print the parcel label.', 'പാഴ്സൽ ലേബൽ പ്രിന്റ്.', 'shipping-label'],
        ['🩹', 'Damaged', 'Damaged', 'Damaged T-shirts and print mistakes.', 'കേടായ ടീ-ഷർട്ടും പ്രിന്റ് തെറ്റുകളും.', 'damaged'],
        ['⭐', 'Saved designs', 'ഡിസൈനുകൾ', 'Reuse a design; order it in one tap.', 'ഡിസൈൻ വീണ്ടും ഉപയോഗിക്കാൻ; ഒറ്റ ടാപ്പിൽ ഓർഡർ.', 'order-from-design'],
        ['🧾', 'Bills', 'ബില്ലുകൾ', 'Invoices; print or send the link.', 'ഇൻവോയ്സ്; പ്രിന്റ് / ലിങ്ക് അയയ്ക്കാൻ.', 'bills'],
        ['💰', 'Payments due', 'കിട്ടാനുള്ള പണം', 'Record payments; see who owes.', 'പേയ്മെന്റ് ചേർക്കാൻ; ആര് തരാനുണ്ട്.', 'payments'],
        ['📝', 'Quotations', 'ക്വട്ടേഷൻ', 'Price offer for a customer.', 'കസ്റ്റമറിന് വില വിവരം.', 'quotation'],
        ['🧮', 'Cost estimate', 'കോസ്റ്റ് എസ്റ്റിമേറ്റ്', 'Cost of custom production.', 'കസ്റ്റം പ്രൊഡക്ഷൻ ചെലവ്.', 'estimate'],
        ['👕', 'Products & stock', 'പ്രോഡക്റ്റ് & സ്റ്റോക്ക്', 'Add stock, fix counts, products and prices.', 'സ്റ്റോക്ക് ചേർക്കാൻ, എണ്ണം ശരിയാക്കാൻ, പ്രോഡക്റ്റ്, വില.', 'add-stock'],
        ['📊', 'Expenses & profit', 'ചെലവ് & ലാഭം', 'Expenses, profit and balance sheet.', 'ചെലവ്, ലാഭം, ബാലൻസ് ഷീറ്റ്.', 'expenses'],
        ['📈', 'Dashboard', 'ഡാഷ്ബോർഡ്', 'The day at a glance.', 'ദിവസം ഒറ്റനോട്ടത്തിൽ.', 'dashboard'],
        ['📌', 'Tasks', 'ടാസ്കുകൾ', 'Your work list; give tasks.', 'നിങ്ങളുടെ ജോലി; ടാസ്ക് കൊടുക്കാൻ.', 'my-tasks'],
        ['🏆', 'Performance', 'പെർഫോമൻസ്', 'Your points and rank.', 'നിങ്ങളുടെ പോയിന്റും റാങ്കും.', 'performance'],
        ['⏰', 'Clock in', 'ക്ലോക്ക് ഇൻ', 'Attendance with your PIN.', 'PIN ഉപയോഗിച്ച് അറ്റൻഡൻസ്.', 'clock-in'],
        ['💵', 'Attendance admin', 'അറ്റൻഡൻസ് അഡ്മിൻ', 'Salary, incentive, payslips.', 'ശമ്പളം, ഇൻസെന്റീവ്, പേസ്ലിപ്പ്.', 'attendance-admin'],
        ['🔔', 'Notifications', 'നോട്ടിഫിക്കേഷൻ', 'Messages on your phone.', 'ഫോണിൽ മെസേജ്.', 'notifications'],
        ['👥', 'Staff', 'സ്റ്റാഫ്', 'Add staff and access.', 'സ്റ്റാഫിനെ ചേർക്കാൻ, ആക്സസ്.', 'staff'],
        ['🙋', 'Customers', 'കസ്റ്റമർമാർ', 'Address book of customers.', 'കസ്റ്റമർമാരുടെ അഡ്രസ് ബുക്ക്.', 'customers'],
        ['💾', 'Backup', 'ബാക്കപ്പ്', 'Keep the data safe.', 'ഡാറ്റ സുരക്ഷിതമാക്കാൻ.', 'backup'],
        ['⚙️', 'Settings', 'സെറ്റിംഗ്സ്', 'Dispatch days, messages, times.', 'ഡിസ്പാച്ച് ദിവസം, മെസേജ്, സമയം.', 'settings'],
    ];
}

/** A step "short :: explanation" → [short, explanation]. */
function sop_step(string $s): array
{
    $p = explode(' :: ', $s, 2);
    return [trim($p[0]), trim($p[1] ?? '')];
}

/** [id, category, who, search words, [English title, steps, tip], [Malayalam title, steps, tip]] */
function sop_topics(): array
{
    return [
        // ------------------------------------------------------------ getting started
        ['login', 'start', 'all', 'login log in sign in password home erp boxes start open app',
            ['Log in', [
                'Open erp.loomaapparels.com. :: Or tap the Looma icon if the app is on your phone.',
                'Type username and password → “Log in”. :: No account yet? The admin gives you one.',
                'Tap a box: Orders, Tasks or Clock in. :: You only see the boxes your job needs.',
                '“⌂ Home” brings you back. :: Tapping the logo does the same.',
            ], ''],
            ['ലോഗിൻ', [
                'erp.loomaapparels.com തുറക്കുക. :: ഫോണിൽ ആപ്പ് ഉണ്ടെങ്കിൽ Looma ഐക്കൺ അമർത്തുക.',
                'username, password → “Log in”. :: അക്കൗണ്ട് ഇല്ലെങ്കിൽ അഡ്മിൻ തരും.',
                'ബോക്സ് തിരഞ്ഞെടുക്കുക: Orders, Tasks, Clock in. :: നിങ്ങളുടെ ജോലിക്ക് വേണ്ട ബോക്സുകൾ മാത്രം കാണും.',
                '“⌂ Home” തിരികെ കൊണ്ടുവരും. :: ലോഗോ അമർത്തിയാലും മതി.',
            ], '']],
        ['install', 'start', 'all', 'install app phone home screen icon android iphone ios computer shortcut add',
            ['Put the app on your phone', [
                'Android: ⋮ → “Add to Home screen”. :: In Chrome, the three dots at the top right. It may say “Install app”.',
                'iPhone: Share (□↑) → “Add to Home Screen”. :: Use Safari. The Share button is at the bottom of the screen.',
                'Computer: install icon in the address bar. :: In Chrome or Edge, at the right end of the address bar.',
                'Open Looma from the new icon. :: It opens full screen like an app. Updates come by themselves.',
            ], ''],
            ['ഫോണിൽ ആപ്പ് ആക്കാൻ', [
                'Android: ⋮ → “Add to Home screen”. :: Chrome-ൽ മുകളിൽ വലത്തെ മൂന്ന് കുത്ത്. “Install app” എന്നും കാണാം.',
                'iPhone: Share (□↑) → “Add to Home Screen”. :: Safari ഉപയോഗിക്കുക. Share ബട്ടൺ സ്ക്രീനിന്റെ താഴെ.',
                'കമ്പ്യൂട്ടർ: അഡ്രസ് ബാറിലെ install ഐക്കൺ. :: Chrome / Edge-ൽ അഡ്രസ് ബാറിന്റെ വലത്തേ അറ്റത്ത്.',
                'പുതിയ Looma ഐക്കണിൽ നിന്ന് തുറക്കുക. :: ആപ്പ് പോലെ ഫുൾ സ്ക്രീനിൽ തുറക്കും. അപ്ഡേറ്റ് തനിയെ വരും.',
            ], '']],
        ['notifications', 'start', 'all', 'notification notifications alert bell push message phone turn on off badge number icon',
            ['Turn on notifications', [
                'Your name (top right) → “🔕 Notifications: off”. :: Or “Turn on” on the blue banner in My tasks.',
                'Tap “Allow”. :: A test message “🔔 Notifications are on” comes.',
                'You get: new tasks, 9 am & 6 pm summary, orders waiting. :: Summaries and orders waiting: admin and production team.',
                'iPhone: only in the Home Screen app. :: Add Looma to the Home Screen first, open it from the icon, then turn on.',
            ], ''],
            ['നോട്ടിഫിക്കേഷൻ ഓൺ ചെയ്യാൻ', [
                'നിങ്ങളുടെ പേര് (മുകളിൽ വലത്) → “🔕 Notifications: off”. :: അല്ലെങ്കിൽ My tasks-ലെ നീല ബാനറിൽ “Turn on”.',
                '“Allow” അമർത്തുക. :: “🔔 Notifications are on” എന്ന ടെസ്റ്റ് മെസേജ് വരും.',
                'കിട്ടും: പുതിയ ടാസ്ക്, 9 മണി & 6 മണി സമ്മറി, കാത്തിരിക്കുന്ന ഓർഡറുകൾ. :: സമ്മറിയും കാത്തിരിക്കുന്ന ഓർഡറും: അഡ്മിനും പ്രൊഡക്ഷൻ ടീമിനും.',
                'iPhone: Home Screen ആപ്പിൽ മാത്രം. :: ആദ്യം Home Screen-ൽ ചേർത്ത് ഐക്കണിൽ നിന്ന് തുറന്ന് ഓൺ ചെയ്യുക.',
            ], '']],
        ['account', 'start', 'all', 'password change my account dark light theme mode appearance night',
            ['Password and dark mode', [
                'Tap your name (top right). :: Your menu opens.',
                'Appearance: Light, Dark or Auto. :: Auto follows the phone’s setting.',
                '“My account” → change password. :: Type the old and the new password.',
            ], ''],
            ['പാസ്‌വേഡും ഡാർക്ക് മോഡും', [
                'നിങ്ങളുടെ പേര് അമർത്തുക (മുകളിൽ വലത്). :: നിങ്ങളുടെ മെനു തുറക്കും.',
                'Appearance: Light, Dark, Auto. :: Auto ഫോണിന്റെ സെറ്റിംഗ് പിന്തുടരും.',
                '“My account” → പാസ്‌വേഡ് മാറ്റാം. :: പഴയതും പുതിയതുമായ പാസ്‌വേഡ് ടൈപ്പ് ചെയ്യുക.',
            ], '']],

        // ------------------------------------------------------------ orders
        ['create-order', 'orders', 'orders', 'create new order add order make order book order customer item tshirt t-shirt how to order step',
            ['Create an order', [
                '“+ New order” → type the customer ID. :: Type the ID, phone or name and pick from the list. A saved customer’s address fills in.',
                'Paste the address in “📋 Paste the customer’s details”. :: Paste the WhatsApp form; name, phone, pincode and address fill in.',
                'Choose T-shirt, colour, size, quantity. :: First the type: T-shirt + print, Plain, Print only or DTF roll.',
                'Add prints and mock-up photos. :: Tap Front / Back / Chest, pick the method and size, add photos with 📷.',
                '“Create order & bill”. :: Check the bill in step 3 first. Your typing is kept until you save.',
            ], 'Many sizes? Use “📏 Many sizes”.'],
            ['ഓർഡർ ഉണ്ടാക്കാൻ', [
                '“+ New order” → കസ്റ്റമർ ID ടൈപ്പ് ചെയ്യുക. :: ID, ഫോൺ, പേര് ടൈപ്പ് ചെയ്ത് ലിസ്റ്റിൽ നിന്ന് എടുക്കുക. സേവ് ചെയ്ത കസ്റ്റമറിന്റെ അഡ്രസ് വരും.',
                'അഡ്രസ് “📋 Paste the customer’s details”-ൽ പേസ്റ്റ് ചെയ്യുക. :: WhatsApp ഫോം പേസ്റ്റ് ചെയ്താൽ പേര്, ഫോൺ, പിൻകോഡ്, അഡ്രസ് നിറയും.',
                'ടീ-ഷർട്ട്, കളർ, സൈസ്, എണ്ണം തിരഞ്ഞെടുക്കുക. :: ആദ്യം ടൈപ്പ്: T-shirt + print, Plain, Print only, DTF roll.',
                'പ്രിന്റും മോക്കപ്പ് ഫോട്ടോയും ചേർക്കുക. :: Front / Back / Chest അമർത്തി മെത്തേഡും സൈസും; ഫോട്ടോ 📷.',
                '“Create order & bill”. :: ആദ്യം സ്റ്റെപ്പ് 3-ലെ ബിൽ നോക്കുക. സേവ് ചെയ്യുന്നത് വരെ ടൈപ്പ് ചെയ്തത് സൂക്ഷിക്കും.',
            ], 'പല സൈസ്? “📏 Many sizes” ഉപയോഗിക്കുക.']],
        ['many-sizes', 'orders', 'orders', 'many sizes size grid s m l xl xxl 3xl 4xl 5xl kids extra size another size add size new size quantity per size same design multiple sizes bulk',
            ['One design, many sizes', [
                'Fill the first item (T-shirt, print, photos). :: Everything except the size and quantity.',
                'Tap “📏 Many sizes”. :: At the top of the item.',
                'Type pieces per size: S 2, M 5, L 3. :: Leave sizes you don’t need empty. The total shows as you type.',
                'Other size (3XL)? Type it → “+ Size”. :: For sizes not in the list. Sizes you always use can be added to the product.',
                '“✓ Make items”. :: You get one item per size, all with the same T-shirt, prints and photos.',
            ], ''],
            ['ഒരു ഡിസൈൻ, പല സൈസ്', [
                'ആദ്യ ഐറ്റം നിറയ്ക്കുക (ടീ-ഷർട്ട്, പ്രിന്റ്, ഫോട്ടോ). :: സൈസും എണ്ണവും ഒഴികെ എല്ലാം.',
                '“📏 Many sizes” അമർത്തുക. :: ഐറ്റത്തിന്റെ മുകളിൽ.',
                'ഓരോ സൈസിനും എണ്ണം: S 2, M 5, L 3. :: വേണ്ടാത്ത സൈസ് ഒഴിച്ചിടുക. ആകെ എണ്ണം കാണിക്കും.',
                'വേറെ സൈസ് (3XL)? ടൈപ്പ് ചെയ്ത് “+ Size”. :: ലിസ്റ്റിൽ ഇല്ലാത്ത സൈസിന്. എപ്പോഴും വേണ്ടത് പ്രോഡക്റ്റിൽ ചേർക്കാം.',
                '“✓ Make items”. :: ഓരോ സൈസിനും ഒരു ഐറ്റം — ഒരേ ടീ-ഷർട്ട്, പ്രിന്റ്, ഫോട്ടോ.',
            ], '']],
        ['paste-address', 'orders', 'orders', 'paste address whatsapp message phone pincode name copy customer details fill shipping form template land mark landmark mobile number email courier please fill the details',
            ['Paste the address from WhatsApp', [
                'Copy the customer’s filled form in WhatsApp. :: Long-press the message → Copy.',
                'Paste it in “📋 Paste the customer’s details”. :: In Shipping address. Long-press the box → Paste.',
                'Name, address, landmark, pincode, mobile, email, courier fill in. :: Size and colour in the form are skipped. Landmark goes at the end of the address.',
                'Check them. :: Fix anything that landed in the wrong box.',
            ], ''],
            ['WhatsApp-ൽ നിന്ന് അഡ്രസ് പേസ്റ്റ്', [
                'WhatsApp-ൽ കസ്റ്റമർ നിറച്ച ഫോം കോപ്പി ചെയ്യുക. :: മെസേജ് ലോങ്-പ്രസ് → Copy.',
                '“📋 Paste the customer’s details”-ൽ പേസ്റ്റ് ചെയ്യുക. :: Shipping address-ൽ. ബോക്സിൽ ലോങ്-പ്രസ് → Paste.',
                'പേര്, അഡ്രസ്, ലാൻഡ്മാർക്ക്, പിൻകോഡ്, മൊബൈൽ, ഇമെയിൽ, കൊറിയർ നിറയും. :: ഫോമിലെ സൈസും കളറും എടുക്കില്ല. ലാൻഡ്മാർക്ക് അഡ്രസിന്റെ അവസാനം ചേരും.',
                'ഒന്ന് പരിശോധിക്കുക. :: തെറ്റായ ബോക്സിൽ വന്നത് ശരിയാക്കുക.',
            ], '']],
        ['repeat-order', 'orders', 'orders', 'repeat order again same order duplicate copy reorder last order previous customer',
            ['Repeat an order', [
                'New order → type the customer ID. :: A customer who ordered before.',
                'See “↻ Last orders”. :: Their last 3 orders show under the customer ID.',
                'Tap “↻ Repeat”. :: A new order opens with the same items, designs and prints.',
                'Change quantities → create. :: Or open any order and tap “Duplicate”.',
            ], ''],
            ['ഓർഡർ വീണ്ടും', [
                'പുതിയ ഓർഡർ → കസ്റ്റമർ ID. :: മുമ്പ് ഓർഡർ ചെയ്ത കസ്റ്റമർ.',
                '“↻ Last orders” കാണാം. :: കസ്റ്റമർ ID-ക്ക് താഴെ അവസാന 3 ഓർഡർ.',
                '“↻ Repeat” അമർത്തുക. :: അതേ ഐറ്റം, ഡിസൈൻ, പ്രിന്റ് ഉള്ള പുതിയ ഓർഡർ തുറക്കും.',
                'എണ്ണം മാറ്റി ഓർഡർ ഉണ്ടാക്കുക. :: അല്ലെങ്കിൽ ഏത് ഓർഡറിലും “Duplicate”.',
            ], '']],
        ['customer-designs', 'orders', 'orders', 'customer design their designs saved design add design to order logo brand repeat design',
            ['Add the customer’s design', [
                'New order → type the customer ID. :: Their saved designs show below it.',
                'Tap a design under “⭐ Their designs”. :: It goes on the first empty item, or a new item.',
                'Prints and photos fill in. :: The mock-ups are attached when you save.',
            ], ''],
            ['കസ്റ്റമറിന്റെ ഡിസൈൻ ചേർക്കാൻ', [
                'പുതിയ ഓർഡർ → കസ്റ്റമർ ID. :: അവരുടെ ഡിസൈനുകൾ താഴെ കാണാം.',
                '“⭐ Their designs”-ലെ ഡിസൈൻ അമർത്തുക. :: ആദ്യത്തെ ഒഴിഞ്ഞ ഐറ്റത്തിൽ അല്ലെങ്കിൽ പുതിയ ഐറ്റത്തിൽ.',
                'പ്രിന്റും ഫോട്ടോയും നിറയും. :: സേവ് ചെയ്യുമ്പോൾ മോക്കപ്പ് ചേരും.',
            ], '']],
        ['draft', 'orders', 'orders', 'draft unsaved lost order page reload closed phone locked internet continue restore',
            ['Order lost halfway?', [
                'Open “+ New order” again. :: On the same phone or computer.',
                'Tap “↩ Continue it” on the yellow bar. :: “Start fresh” throws the draft away.',
                'Add the photos again. :: Photos are not kept in the draft.',
            ], ''],
            ['പാതിയിൽ ഓർഡർ പോയോ?', [
                '“+ New order” വീണ്ടും തുറക്കുക. :: അതേ ഫോണിൽ / കമ്പ്യൂട്ടറിൽ.',
                'മഞ്ഞ ബാറിലെ “↩ Continue it”. :: “Start fresh” ഡ്രാഫ്റ്റ് കളയും.',
                'ഫോട്ടോ വീണ്ടും ചേർക്കുക. :: ഫോട്ടോ ഡ്രാഫ്റ്റിൽ സൂക്ഷിക്കില്ല.',
            ], '']],
        ['production-order', 'orders', 'orders', 'production order dispatch date due date change date 2 days deadline delivery date dtf locked embroidery time',
            ['Dispatch date and production orders', [
                'DTF: 2 working days, locked 🔒. :: Sundays are not counted.',
                'We make the T-shirts? Tick “🏭 Production order”. :: At the top of the order. Then you set the dispatch date.',
                'Embroidery / HD / Puff / Screen: you can set the date. :: These take longer, so the date opens.',
                'Only the admin changes a DTF date. :: Ask the admin if a DTF order needs more time.',
            ], ''],
            ['ഡിസ്പാച്ച് തീയതിയും പ്രൊഡക്ഷൻ ഓർഡറും', [
                'DTF: 2 പ്രവൃത്തി ദിവസം, ലോക്ക് 🔒. :: ഞായറാഴ്ച കണക്കാക്കില്ല.',
                'ടീ-ഷർട്ട് നമ്മൾ ഉണ്ടാക്കുന്നോ? “🏭 Production order” ടിക്ക്. :: ഓർഡറിന്റെ മുകളിൽ. പിന്നെ ഡിസ്പാച്ച് തീയതി നിങ്ങൾ ഇടുക.',
                'Embroidery / HD / Puff / Screen: തീയതി മാറ്റാം. :: ഇവയ്ക്ക് കൂടുതൽ സമയം വേണം, അതിനാൽ തീയതി തുറക്കും.',
                'DTF തീയതി അഡ്മിന് മാത്രം. :: DTF ഓർഡറിന് കൂടുതൽ സമയം വേണമെങ്കിൽ അഡ്മിനോട് പറയുക.',
            ], '']],
        ['print-methods', 'orders', 'orders', 'print method dtf embroidery emb puff hd high density screen print stitches digitizing price front back chest custom place size a3 a4',
            ['Add a print', [
                'Tap Front / Back / Chest / Custom. :: On the item, under prints.',
                'Method: DTF, Embroidery, Puff, HD, Screen. :: Embroidery: type the stitches. HD needs 10+ pcs, screen 60+.',
                'Choose size (A3, A4, Logo …). :: “Other” to type a size.',
                'Write what to print. :: Text, colour and position. ✕ removes the print.',
            ], ''],
            ['പ്രിന്റ് ചേർക്കാൻ', [
                'Front / Back / Chest / Custom അമർത്തുക. :: ഐറ്റത്തിൽ, പ്രിന്റിന് താഴെ.',
                'Method: DTF, Embroidery, Puff, HD, Screen. :: Embroidery: സ്റ്റിച്ച് എണ്ണം. HD 10+ പീസ്, സ്ക്രീൻ 60+.',
                'സൈസ് (A3, A4, Logo …). :: വേറെ സൈസിന് “Other”.',
                'എന്ത് പ്രിന്റ് ചെയ്യണം എന്ന് എഴുതുക. :: ടെക്സ്റ്റ്, കളർ, സ്ഥാനം. ✕ പ്രിന്റ് മാറ്റും.',
            ], '']],
        ['neck-label', 'orders', 'orders', 'neck label chest logo brand label tag customer brand',
            ['Neck label / chest logo', [
                'On the item, switch on “🏷 Neck label” or “🔰 Chest logo”. :: You can add a photo of it too.',
                'Type the brand name. :: The neck label fills with the customer name; change it if needed.',
                'When done, tick it on the order. :: Packing waits until these are done.',
            ], ''],
            ['നെക്ക് ലേബൽ / ചെസ്റ്റ് ലോഗോ', [
                'ഐറ്റത്തിൽ “🏷 Neck label” / “🔰 Chest logo” ഓൺ ചെയ്യുക. :: അതിന്റെ ഫോട്ടോയും ചേർക്കാം.',
                'ബ്രാൻഡ് പേര് ടൈപ്പ് ചെയ്യുക. :: നെക്ക് ലേബലിൽ കസ്റ്റമറിന്റെ പേര് വരും; വേണമെങ്കിൽ മാറ്റുക.',
                'കഴിഞ്ഞാൽ ഓർഡറിൽ ടിക്ക് ചെയ്യുക. :: ഇവ കഴിയാതെ പാക്കിംഗ് പറ്റില്ല.',
            ], '']],
        ['mockups', 'orders', 'orders', 'mockup mock-up photo image picture upload design file camera',
            ['Add photos', [
                'On the item, tap 📷. :: The photo box of that item.',
                'Choose or take photos. :: Big phone photos are made smaller by themselves.',
                'Save the order. :: To remove a photo: tick “Remove” under it when editing.',
            ], ''],
            ['ഫോട്ടോ ചേർക്കാൻ', [
                'ഐറ്റത്തിൽ 📷 അമർത്തുക. :: ആ ഐറ്റത്തിന്റെ ഫോട്ടോ ബോക്സ്.',
                'ഫോട്ടോ തിരഞ്ഞെടുക്കുക / എടുക്കുക. :: വലിയ ഫോട്ടോ തനിയെ ചെറുതാക്കും.',
                'ഓർഡർ സേവ് ചെയ്യുക. :: ഫോട്ടോ മാറ്റാൻ: എഡിറ്റിൽ “Remove” ടിക്ക് ചെയ്യുക.',
            ], '']],
        ['edit-order', 'orders', 'orders', 'edit order change order modify update cancel delete remove item wrong mistake',
            ['Edit an order', [
                'Open the order → “✎ Edit”. :: Tap the order card in Orders.',
                'Change what you need. :: 🗑 removes an item; “+ Add another product” adds one.',
                '“Save changes”. :: Every change is kept in the order history.',
            ], ''],
            ['ഓർഡർ എഡിറ്റ് ചെയ്യാൻ', [
                'ഓർഡർ തുറക്കുക → “✎ Edit”. :: Orders-ൽ ഓർഡർ കാർഡ് അമർത്തുക.',
                'വേണ്ടത് മാറ്റുക. :: 🗑 ഐറ്റം മാറ്റും; “+ Add another product” പുതിയത് ചേർക്കും.',
                '“Save changes”. :: എല്ലാ മാറ്റവും ഓർഡർ ഹിസ്റ്ററിയിൽ ഉണ്ടാകും.',
            ], '']],
        ['find-order', 'orders', 'all', 'find order search order filter tab delayed due today to print to pack to ship shipped date customer phone print method dtf embroidery',
            ['Find an order', [
                'Open “Orders”. :: In the top bar.',
                'Tabs: To print, To pack, To ship, Delayed … :: Each tab shows how many orders are in it.',
                'Search: name, phone or order no. :: Also customer ID, tracking number or product.',
                'Print row: DTF, Embroidery, HD … :: Shows only orders with that print, or 🏭 Production orders.',
            ], ''],
            ['ഓർഡർ കണ്ടെത്താൻ', [
                '“Orders” തുറക്കുക. :: മുകളിലെ ബാറിൽ.',
                'ടാബുകൾ: To print, To pack, To ship, Delayed … :: ഓരോ ടാബിലും എത്ര ഓർഡർ എന്ന് കാണാം.',
                'സെർച്ച്: പേര്, ഫോൺ, ഓർഡർ നമ്പർ. :: കസ്റ്റമർ ID, ട്രാക്കിംഗ് നമ്പർ, പ്രോഡക്റ്റ് എന്നിവയും.',
                'Print വരി: DTF, Embroidery, HD … :: ആ പ്രിന്റ് ഉള്ള ഓർഡർ മാത്രം, അല്ലെങ്കിൽ 🏭 Production.',
            ], '']],
        ['cod-proof', 'orders', 'orders', 'cod cash on delivery prepaid payment amount photo video proof customer wants to see',
            ['COD and photo / video', [
                'Choose ✓ Prepaid or 💵 COD (type amount). :: In Delivery & payment. The amount shows in words like on the label.',
                'Customer wants to see? Tick 📷 Photo / 🎥 Video. :: The order stays red until it is sent.',
                'After sending: “Mark … sent”. :: On the order page, after sending on WhatsApp.',
            ], ''],
            ['COD, ഫോട്ടോ / വീഡിയോ', [
                '✓ Prepaid അല്ലെങ്കിൽ 💵 COD (തുക). :: Delivery & payment-ൽ. തുക ലേബലിലെ പോലെ അക്ഷരത്തിലും.',
                'കസ്റ്റമറിന് കാണണോ? 📷 Photo / 🎥 Video ടിക്ക്. :: അയയ്ക്കുന്നത് വരെ ഓർഡർ ചുവപ്പിൽ.',
                'അയച്ച ശേഷം: “Mark … sent”. :: WhatsApp-ൽ അയച്ച ശേഷം ഓർഡർ പേജിൽ.',
            ], '']],
        ['whatsapp', 'orders', 'orders', 'whatsapp message send customer confirm order confirmation shipping update tracking',
            ['WhatsApp the customer', [
                'Open the order → “WhatsApp”. :: The green button on the order page.',
                'Pick the message. :: Order confirmation has the items and the dispatch date.',
                'Shipped? “Send shipping update on WhatsApp”. :: Courier and tracking number are filled in.',
            ], ''],
            ['കസ്റ്റമറിന് WhatsApp', [
                'ഓർഡർ തുറക്കുക → “WhatsApp”. :: ഓർഡർ പേജിലെ പച്ച ബട്ടൺ.',
                'മെസേജ് തിരഞ്ഞെടുക്കുക. :: ഓർഡർ കൺഫർമേഷനിൽ ഐറ്റവും ഡിസ്പാച്ച് തീയതിയും.',
                'ഷിപ്പ് ചെയ്തോ? “Send shipping update on WhatsApp”. :: കൊറിയറും ട്രാക്കിംഗ് നമ്പറും നിറഞ്ഞിരിക്കും.',
            ], '']],

        // ------------------------------------------------------------ production
        ['print-list', 'production', 'orders', 'print list printing what to print blanks to pick today printer not ready hold',
            ['Print list', [
                'Open “Print list”. :: In the top bar.',
                '“Blanks to pick”: T-shirts to take. :: GSM, colour, size and how many, from stock.',
                '“Items to print”: tick Printed when done. :: Oldest dispatch date first, with mock-ups.',
                'Not ready? “⏸ Not ready”. :: Waiting for the customer or payment. “▶ Ready to print” brings it back.',
            ], ''],
            ['പ്രിന്റ് ലിസ്റ്റ്', [
                '“Print list” തുറക്കുക. :: മുകളിലെ ബാറിൽ.',
                '“Blanks to pick”: എടുക്കേണ്ട ടീ-ഷർട്ട്. :: GSM, കളർ, സൈസ്, എണ്ണം — സ്റ്റോക്കിൽ നിന്ന്.',
                '“Items to print”: കഴിഞ്ഞാൽ Printed ടിക്ക്. :: ആദ്യം ഡിസ്പാച്ച് തീയതി അടുത്തത്, മോക്കപ്പ് സഹിതം.',
                'തയ്യാറല്ലേ? “⏸ Not ready”. :: കസ്റ്റമർ / പേയ്മെന്റ് കാത്തിരിക്കുന്നു. “▶ Ready to print” തിരികെ.',
            ], '']],
        ['ticks', 'production', 'orders', 'tick print processed printed packed shipped status stage progress mark done courier tracking',
            ['Tick the order’s progress', [
                'Print processed → Printed → Packed → Shipped. :: On the order card or the order page.',
                'Printed: tick each item. :: Or “✓ Mark all printed” on the order page.',
                'Shipped: add courier + tracking first. :: Then send the WhatsApp update.',
                'Wrong tick? Tap again to undo. :: Each tick saves who did it and when (your points).',
            ], ''],
            ['ഓർഡർ പുരോഗതി ടിക്ക്', [
                'Print processed → Printed → Packed → Shipped. :: ഓർഡർ കാർഡിലോ ഓർഡർ പേജിലോ.',
                'Printed: ഓരോ ഐറ്റവും ടിക്ക്. :: അല്ലെങ്കിൽ ഓർഡർ പേജിൽ “✓ Mark all printed”.',
                'Shipped: ആദ്യം കൊറിയറും ട്രാക്കിംഗും. :: പിന്നെ WhatsApp അപ്ഡേറ്റ് അയയ്ക്കുക.',
                'തെറ്റിയോ? വീണ്ടും അമർത്തി മാറ്റാം. :: ഓരോ ടിക്കും ആര്, എപ്പോൾ എന്ന് രേഖപ്പെടുത്തും (പോയിന്റ്).',
            ], '']],
        ['received', 'production', 'orders', 'received embroidery received hd received puff received screen received outside print came back dispatch date tick',
            ['Embroidery / HD came back', [
                'Tick “Embroidery received” (or HD / Puff / Screen). :: Next to Print processed, on the card or order page.',
                'The dispatch date moves by itself. :: Only when every outside print of the order is ticked.',
                '1–15 pcs: 2 days · 16–30: 3 · more: 4. :: Working days after the last tick. Puff / screen: 2 days.',
            ], ''],
            ['Embroidery / HD തിരികെ വന്നാൽ', [
                '“Embroidery received” ടിക്ക് (HD / Puff / Screen). :: Print processed-ന് അടുത്ത്, കാർഡിലോ ഓർഡർ പേജിലോ.',
                'ഡിസ്പാച്ച് തീയതി തനിയെ മാറും. :: ഓർഡറിന്റെ എല്ലാ പുറം പ്രിന്റും ടിക്ക് ആയാൽ മാത്രം.',
                '1–15 പീസ്: 2 ദിവസം · 16–30: 3 · കൂടുതൽ: 4. :: അവസാന ടിക്കിന് ശേഷമുള്ള പ്രവൃത്തി ദിവസം. Puff / screen: 2.',
            ], '']],
        ['shipping-label', 'production', 'orders', 'shipping label packing slip print label courier address sticker parcel cod amount',
            ['Print a shipping label', [
                'Open the order → “🖨 Shipping label”. :: Many at once: Orders → To ship → “🖨 Labels”.',
                'Check address and COD. :: Courier and tracking too.',
                '“💾 Save & print”. :: A label for a parcel that is not an order: “Shipping labels” → “+ New label”.',
            ], ''],
            ['ഷിപ്പിംഗ് ലേബൽ', [
                'ഓർഡർ തുറക്കുക → “🖨 Shipping label”. :: ഒന്നിച്ച്: Orders → To ship → “🖨 Labels”.',
                'അഡ്രസും COD-ഉം പരിശോധിക്കുക. :: കൊറിയറും ട്രാക്കിംഗും.',
                '“💾 Save & print”. :: ഓർഡർ അല്ലാത്ത പാഴ്സലിന്: “Shipping labels” → “+ New label”.',
            ], '']],
        ['damaged', 'production', 'orders', 'damaged damage hole mistake printing mistake embroidery mistake reject wasted spoiled tshirt red mark',
            ['Damaged T-shirt', [
                'Tap “Damaged”. :: In the top bar.',
                'Pick product, colour, pieces per size. :: Like adding stock.',
                'Why: Plain damage / Printing / Embroidery mistake. :: Plain = holes or fabric. A mistake is a red mark on your performance.',
                '“💾 Save & take out of stock”. :: Wrong entry? The admin can delete it and the pieces come back.',
            ], ''],
            ['കേടായ ടീ-ഷർട്ട്', [
                '“Damaged” അമർത്തുക. :: മുകളിലെ ബാറിൽ.',
                'പ്രോഡക്റ്റ്, കളർ, ഓരോ സൈസിലും എണ്ണം. :: സ്റ്റോക്ക് ചേർക്കുന്നത് പോലെ.',
                'കാരണം: Plain damage / Printing / Embroidery mistake. :: Plain = ഓട്ട, തുണി. തെറ്റ് പെർഫോമൻസിൽ ചുവന്ന മാർക്ക്.',
                '“💾 Save & take out of stock”. :: തെറ്റിയോ? അഡ്മിൻ ഡിലീറ്റ് ചെയ്താൽ പീസ് തിരികെ വരും.',
            ], '']],
        ['morning', 'production', 'orders', 'morning summary 9 am evening summary 6 pm shipped today printed today pending notification badge number app icon waiting to ship pending orders today work',
            ['9 am & 6 pm summary, icon number', [
                '9 am (Mon–Sat): ship today, print today, delayed. :: Orders and T-shirts for each. Tap it to open the orders.',
                '6 pm (Mon–Sat): shipped today, printed today, pending. :: How the day went, and what is still waiting to ship.',
                'Icon number = orders waiting over 24 hours. :: Not shipped 24 hours after they were made. A message comes when it goes up.',
                'Turn on notifications first. :: For the admin and the production team (not creative). Your name → “🔕 Notifications: off” → Allow.',
            ], ''],
            ['രാവിലെ 9, വൈകിട്ട് 6 സമ്മറി, ഐക്കൺ നമ്പർ', [
                'രാവിലെ 9 (തിങ്കൾ–ശനി): ഇന്ന് ഷിപ്പ്, ഇന്ന് പ്രിന്റ്, വൈകിയവ. :: ഓർഡറും ടീ-ഷർട്ടും. അമർത്തിയാൽ ഓർഡറുകൾ തുറക്കും.',
                'വൈകിട്ട് 6 (തിങ്കൾ–ശനി): ഇന്ന് ഷിപ്പ് ചെയ്തത്, പ്രിന്റ് ചെയ്തത്, ബാക്കി. :: ദിവസം എങ്ങനെ പോയി, ഇനിയും ഷിപ്പ് ചെയ്യാനുള്ളത്.',
                'ഐക്കൺ നമ്പർ = 24 മണിക്കൂർ കഴിഞ്ഞിട്ടും ഷിപ്പ് ചെയ്യാത്തവ. :: എണ്ണം കൂടുമ്പോൾ മെസേജ് വരും.',
                'ആദ്യം നോട്ടിഫിക്കേഷൻ ഓൺ ചെയ്യുക. :: അഡ്മിനും പ്രൊഡക്ഷൻ ടീമിനും (ക്രിയേറ്റീവ് അല്ല). നിങ്ങളുടെ പേര് → “🔕 Notifications: off” → Allow.',
            ], '']],

        // ------------------------------------------------------------ designs
        ['saved-design', 'designs', 'orders', 'saved design new design create design mockup template reuse product print',
            ['Make a saved design', [
                '“Saved designs” → “+ New design”. :: In More.',
                'Name, photos, print details. :: The T-shirt and neck label are optional.',
                '“Made for customers”: C101, C205. :: Customers who order it are added by themselves.',
                '“Save design”. :: Now anyone can add it to an order in one tap.',
            ], ''],
            ['ഡിസൈൻ സേവ് ചെയ്യാൻ', [
                '“Saved designs” → “+ New design”. :: More-ൽ.',
                'പേര്, ഫോട്ടോ, പ്രിന്റ് വിവരം. :: ടീ-ഷർട്ടും നെക്ക് ലേബലും ഓപ്ഷണൽ.',
                '“Made for customers”: C101, C205. :: ഓർഡർ ചെയ്യുന്ന കസ്റ്റമർമാർ തനിയെ ചേരും.',
                '“Save design”. :: ഇനി ആർക്കും ഒറ്റ ടാപ്പിൽ ഓർഡറിൽ ചേർക്കാം.',
            ], '']],
        ['order-from-design', 'designs', 'orders', 'order from design design customer link map customer order this design quick order',
            ['Order from a design', [
                'Open “Saved designs”. :: Search a design name or a customer ID.',
                'Tap “＋ Order for C101”. :: The order opens with the customer, address and design filled in.',
                'Add sizes → create the order. :: Use “📏 Many sizes”.',
            ], ''],
            ['ഡിസൈനിൽ നിന്ന് ഓർഡർ', [
                '“Saved designs” തുറക്കുക. :: ഡിസൈൻ പേരോ കസ്റ്റമർ ID-യോ സെർച്ച് ചെയ്യുക.',
                '“＋ Order for C101” അമർത്തുക. :: കസ്റ്റമർ, അഡ്രസ്, ഡിസൈൻ നിറഞ്ഞ ഓർഡർ തുറക്കും.',
                'സൈസ് ചേർത്ത് ഓർഡർ ഉണ്ടാക്കുക. :: “📏 Many sizes” ഉപയോഗിക്കുക.',
            ], '']],

        // ------------------------------------------------------------ money
        ['bills', 'money', 'money', 'bill invoice gst tax invoice print pdf share link customer bill number cancel bill',
            ['Bills', [
                'A bill is made with the order. :: GST by the customer’s state (Kerala: CGST + SGST).',
                'Open “Bills” → the bill. :: Month arrows and search at the top.',
                '“🖨 Print / PDF” or “🔗 Copy link”. :: Send the link on WhatsApp. “✎ Edit” changes lines.',
            ], ''],
            ['ബിൽ', [
                'ഓർഡറിനൊപ്പം ബിൽ ഉണ്ടാകും. :: കസ്റ്റമറിന്റെ സ്റ്റേറ്റ് അനുസരിച്ച് GST (കേരളം: CGST + SGST).',
                '“Bills” → ബിൽ തുറക്കുക. :: മുകളിൽ മാസം മാറ്റാനും സെർച്ചിനും.',
                '“🖨 Print / PDF” അല്ലെങ്കിൽ “🔗 Copy link”. :: ലിങ്ക് WhatsApp-ൽ അയയ്ക്കാം. “✎ Edit” ലൈൻ മാറ്റാൻ.',
            ], '']],
        ['payments', 'money', 'money', 'payment paid received money advance balance due dues pending payment collect upi cash record',
            ['Record a payment', [
                'Open the bill → “+ Record payment”. :: Or the 💰 Payment box on the order.',
                'Amount, date, UPI / Cash → save. :: Part payments are fine.',
                '“Payments due”: who still owes. :: With a WhatsApp reminder button.',
            ], ''],
            ['പേയ്മെന്റ് ചേർക്കാൻ', [
                'ബിൽ തുറക്കുക → “+ Record payment”. :: അല്ലെങ്കിൽ ഓർഡറിലെ 💰 Payment ബോക്സ്.',
                'തുക, തീയതി, UPI / Cash → സേവ്. :: ഭാഗിക പേയ്മെന്റും ചേർക്കാം.',
                '“Payments due”: ആര് തരാനുണ്ട്. :: WhatsApp ഓർമ്മപ്പെടുത്തൽ ബട്ടൺ സഹിതം.',
            ], '']],
        ['quotation', 'money', 'money', 'quotation quote estimate price offer customer price list send quotation',
            ['Quotation', [
                'Fill a new order. :: Items and prints, like a real order.',
                'Tap “📝 Quotation” (nothing is saved). :: At the bottom, next to Create order.',
                'Print or send the link. :: Or “Quotations” → “📝 New quotation”.',
            ], ''],
            ['ക്വട്ടേഷൻ', [
                'പുതിയ ഓർഡർ നിറയ്ക്കുക. :: ഐറ്റവും പ്രിന്റും, സാധാരണ ഓർഡർ പോലെ.',
                '“📝 Quotation” അമർത്തുക (സേവ് ആകില്ല). :: താഴെ, Create order-ന് അടുത്ത്.',
                'പ്രിന്റ് / ലിങ്ക് അയയ്ക്കുക. :: അല്ലെങ്കിൽ “Quotations” → “📝 New quotation”.',
            ], '']],
        ['estimate', 'money', 'orders', 'cost estimate production cost fabric gsm per piece price custom production calculate making cost',
            ['Cost estimate', [
                'More → “Cost estimate” → “+ New estimate”. :: For custom production.',
                'Fabric, sizes, making costs. :: ₹ per kg, grams per piece, stitching, printing …',
                'See cost per piece → save. :: Saved ones are in “Saved estimates”.',
            ], ''],
            ['കോസ്റ്റ് എസ്റ്റിമേറ്റ്', [
                'More → “Cost estimate” → “+ New estimate”. :: കസ്റ്റം പ്രൊഡക്ഷന്.',
                'ഫാബ്രിക്, സൈസ്, ചെലവുകൾ. :: കിലോയ്ക്ക് ₹, പീസിന് ഗ്രാം, തയ്യൽ, പ്രിന്റിംഗ് …',
                'ഒരു പീസിന്റെ ചെലവ് കാണുക → സേവ്. :: “Saved estimates”-ൽ പിന്നീട് കാണാം.',
            ], '']],
        ['expenses', 'money', 'money', 'expense expenses spend purchase rent salary courier cost profit loss balance sheet accounts',
            ['Expenses and profit', [
                'More → “Expenses” → add an expense. :: Date, category, amount, note.',
                '“📊 Profit & balance sheet” for the month. :: Sales, costs, profit; what the business owns and owes.',
            ], ''],
            ['ചെലവും ലാഭവും', [
                'More → “Expenses” → ചെലവ് ചേർക്കുക. :: തീയതി, വിഭാഗം, തുക, കുറിപ്പ്.',
                '“📊 Profit & balance sheet” മാസത്തെ കണക്ക്. :: വിൽപ്പന, ചെലവ്, ലാഭം; ആസ്തിയും ബാധ്യതയും.',
            ], '']],

        // ------------------------------------------------------------ stock
        ['add-stock', 'stock', 'money', 'add stock new stock received purchase tshirt t-shirts blank stock count production batch fabric',
            ['Add stock', [
                '“Products & stock” → “＋ Add stock”. :: In the top bar.',
                'Product, colour, pieces per size. :: Each size shows what is in stock now.',
                '“💾 Save stock”. :: Made them yourselves? Fill “🧵 Production batch” for cost per piece. Stock goes down by itself with bills.',
            ], ''],
            ['സ്റ്റോക്ക് ചേർക്കാൻ', [
                '“Products & stock” → “＋ Add stock”. :: മുകളിലെ ബാറിൽ.',
                'പ്രോഡക്റ്റ്, കളർ, ഓരോ സൈസിലും എണ്ണം. :: ഓരോ സൈസിലും ഇപ്പോഴത്തെ സ്റ്റോക്ക് കാണാം.',
                '“💾 Save stock”. :: നമ്മൾ ഉണ്ടാക്കിയതാണോ? “🧵 Production batch” നിറച്ചാൽ ഒരു പീസിന്റെ ചെലവ്. ബിൽ വരുമ്പോൾ സ്റ്റോക്ക് തനിയെ കുറയും.',
            ], '']],
        ['stock-mistake', 'stock', 'money', 'wrong stock mistake correct stock fix stock count set count adjust remove stock typing error',
            ['Fix a wrong stock number', [
                '“＋ Add stock” → same product, colour. :: The one that is wrong.',
                'Choose “🔢 Set count (counted)”. :: Instead of “Add to stock”.',
                'Type the real number → save. :: Only the wrong sizes; leave the rest empty. Don’t use “Damaged” for this.',
            ], ''],
            ['തെറ്റായ സ്റ്റോക്ക് ശരിയാക്കാൻ', [
                '“＋ Add stock” → അതേ പ്രോഡക്റ്റ്, കളർ. :: തെറ്റിയത്.',
                '“🔢 Set count (counted)” തിരഞ്ഞെടുക്കുക. :: “Add to stock”-ന് പകരം.',
                'ശരിയായ എണ്ണം → സേവ്. :: തെറ്റിയ സൈസ് മാത്രം; ബാക്കി ഒഴിച്ചിടുക. ഇതിന് “Damaged” ഉപയോഗിക്കരുത്.',
            ], '']],
        ['products', 'stock', 'admin', 'new product add product colour color size price gsm catalog order form options courier print price',
            ['Products, colours, sizes, prices', [
                '“Products & stock” → “＋ New product”. :: GSM, name, price, sizes, colours.',
                '“Edit product”: price, colours, sizes. :: Quantity prices too. Hide a product you don’t sell.',
                'More → “Order form options”: couriers, print prices. :: Also GSM options and print sizes.',
            ], ''],
            ['പ്രോഡക്റ്റ്, കളർ, സൈസ്, വില', [
                '“Products & stock” → “＋ New product”. :: GSM, പേര്, വില, സൈസ്, കളർ.',
                '“Edit product”: വില, കളർ, സൈസ്. :: എണ്ണത്തിനനുസരിച്ചുള്ള വിലയും. വിൽക്കാത്തത് മറയ്ക്കാം.',
                'More → “Order form options”: കൊറിയർ, പ്രിന്റ് വില. :: GSM, പ്രിന്റ് സൈസ് എന്നിവയും.',
            ], '']],

        // ------------------------------------------------------------ tasks
        ['my-tasks', 'tasks', 'all', 'my tasks task todo working review done status move board deadline due approval',
            ['Do your tasks', [
                'Open “Tasks”. :: Late tasks are red.',
                'Open a task and read it. :: Details, script and reference images are in the page.',
                'Move: To do → Working on it → Done. :: Drag the card on the board. “For review” goes back to the person who gave it.',
                'Late = half points. :: Done after the due date.',
            ], ''],
            ['നിങ്ങളുടെ ടാസ്ക്', [
                '“Tasks” തുറക്കുക. :: വൈകിയവ ചുവപ്പിൽ.',
                'ടാസ്ക് തുറന്ന് വായിക്കുക. :: വിവരം, സ്ക്രിപ്റ്റ്, റഫറൻസ് ചിത്രം പേജിൽ.',
                'മാറ്റുക: To do → Working on it → Done. :: ബോർഡിൽ കാർഡ് വലിച്ചിടാം. “For review” തന്നയാളിലേക്ക് പോകും.',
                'വൈകിയാൽ പകുതി പോയിന്റ്. :: ഡ്യൂ ഡേറ്റ് കഴിഞ്ഞ് തീർത്താൽ.',
            ], '']],
        ['give-task', 'tasks', 'admin', 'give task assign task new task staff deadline priority video script template another person',
            ['Give a task', [
                'Tasks → “＋ New task”. :: At the top right.',
                'Title, person, due date. :: Pick a template (e.g. video script) and tick “👀 Needs my approval” if needed.',
                'Save — they get a notification. :: If they turned notifications on.',
            ], ''],
            ['ടാസ്ക് കൊടുക്കാൻ', [
                'Tasks → “＋ New task”. :: മുകളിൽ വലത്.',
                'തലക്കെട്ട്, ആൾ, ഡ്യൂ ഡേറ്റ്. :: ടെംപ്ലേറ്റ് (ഉദാ: വീഡിയോ സ്ക്രിപ്റ്റ്); വേണമെങ്കിൽ “👀 Needs my approval”.',
                'സേവ് — അവർക്ക് നോട്ടിഫിക്കേഷൻ പോകും. :: അവർ നോട്ടിഫിക്കേഷൻ ഓൺ ചെയ്തിട്ടുണ്ടെങ്കിൽ.',
            ], '']],
        ['task-page', 'tasks', 'all', 'task page write notes script blocks comment image heading checklist table view calendar view',
            ['Write in a task', [
                'Type in the page; “/” for lists and images. :: Headings, checklists, quotes too.',
                'It saves by itself. :: Two people can write at the same time.',
                '💬 to comment on a line. :: Board, Table and Calendar views are at the top.',
            ], ''],
            ['ടാസ്കിൽ എഴുതാൻ', [
                'പേജിൽ ടൈപ്പ് ചെയ്യുക; ലിസ്റ്റ്, ചിത്രം — “/”. :: ഹെഡിംഗ്, ചെക്ക്‌ലിസ്റ്റ്, ക്വോട്ട് എന്നിവയും.',
                'തനിയെ സേവ് ആകും. :: രണ്ട് പേർക്ക് ഒരേ സമയം എഴുതാം.',
                'ഒരു വരിയിൽ കമന്റ്: 💬. :: Board, Table, Calendar മുകളിൽ.',
            ], '']],
        ['performance', 'tasks', 'all', 'performance points score rank work score team leaderboard late on time mistakes',
            ['Your points', [
                'Open “Your performance”. :: Or tap ⭐ at the top.',
                'Order 5 + 1/T-shirt · printed 1/pc · packed 1 · task 10. :: Print processed: 1 + 0.5 per T-shirt.',
                'Late work = half points. :: After the order’s dispatch date or the task’s due date. Mistakes show red.',
            ], ''],
            ['നിങ്ങളുടെ പോയിന്റ്', [
                '“Your performance” തുറക്കുക. :: അല്ലെങ്കിൽ മുകളിലെ ⭐.',
                'ഓർഡർ 5 + 1/ടീ-ഷർട്ട് · പ്രിന്റ് 1/പീസ് · പാക്ക് 1 · ടാസ്ക് 10. :: Print processed: 1 + ഓരോ ടീ-ഷർട്ടിനും 0.5.',
                'വൈകിയ ജോലി = പകുതി പോയിന്റ്. :: ഡിസ്പാച്ച് / ഡ്യൂ ഡേറ്റ് കഴിഞ്ഞാൽ. തെറ്റുകൾ ചുവപ്പിൽ.',
            ], '']],

        // ------------------------------------------------------------ attendance
        ['clock-in', 'attendance', 'all', 'clock in clock out attendance pin punch in punch out office computer kiosk leave',
            ['Clock in / out', [
                'Open “Clock in” on the office computer. :: Home screen → Clock in.',
                'Tap your name, type your PIN. :: The PIN, not your app password.',
                '“Clock in” on arrival, “Clock out” when leaving. :: Wrong time? Tell the admin.',
            ], ''],
            ['ക്ലോക്ക് ഇൻ / ഔട്ട്', [
                'ഓഫീസ് കമ്പ്യൂട്ടറിൽ “Clock in” തുറക്കുക. :: ഹോം സ്ക്രീൻ → Clock in.',
                'പേര് അമർത്തി PIN ടൈപ്പ് ചെയ്യുക. :: PIN മാത്രം, ആപ്പ് പാസ്‌വേഡ് അല്ല.',
                'വരുമ്പോൾ “Clock in”, പോകുമ്പോൾ “Clock out”. :: സമയം തെറ്റിയോ? അഡ്മിനോട് പറയുക.',
            ], '']],
        ['attendance-admin', 'attendance', 'admin', 'attendance admin salary payslip incentive sales leave holiday working days till date someone leaving pay',
            ['Salary and incentive', [
                'More → “Attendance admin” → Salary. :: No second password for the admin.',
                'Sales come from orders by themselves. :: Before GST and shipping. Type a figure to change it.',
                'Someone leaving: “Salary till a date”. :: Pick the date → “Calculate till date”.',
                'Print payslips. :: From the salary table.',
            ], ''],
            ['ശമ്പളവും ഇൻസെന്റീവും', [
                'More → “Attendance admin” → Salary. :: അഡ്മിന് രണ്ടാമത് പാസ്‌വേഡ് വേണ്ട.',
                'വിൽപ്പന ഓർഡറിൽ നിന്ന് തനിയെ വരും. :: GST, ഷിപ്പിംഗ് ഇല്ലാതെ. മാറ്റാൻ തുക ടൈപ്പ് ചെയ്യുക.',
                'ഒരാൾ പോകുന്നു: “Salary till a date”. :: തീയതി → “Calculate till date”.',
                'പേസ്ലിപ്പ് പ്രിന്റ് ചെയ്യുക. :: ശമ്പള ടേബിളിൽ നിന്ന്.',
            ], '']],

        // ------------------------------------------------------------ admin
        ['staff', 'admin', 'admin', 'add staff new employee user account permission access job role pin attendance details track performance',
            ['Add staff', [
                'More → “Staff” → add name, username, password, job. :: Job: Production, Video editor, Marketing …',
                'Fill the Attendance part (salary, PIN). :: PIN 4–6 digits, for the clock-in computer.',
                'Set what they can see. :: Open the person → permissions and “Track performance”.',
            ], ''],
            ['സ്റ്റാഫിനെ ചേർക്കാൻ', [
                'More → “Staff” → പേര്, username, password, ജോലി. :: ജോലി: Production, Video editor, Marketing …',
                'Attendance ഭാഗം (ശമ്പളം, PIN) നിറയ്ക്കുക. :: PIN 4–6 അക്കം, ക്ലോക്ക്-ഇൻ കമ്പ്യൂട്ടറിന്.',
                'എന്തൊക്കെ കാണാം എന്ന് സെറ്റ് ചെയ്യുക. :: ആളെ തുറന്ന് permissions, “Track performance”.',
            ], '']],
        ['customers', 'admin', 'admin', 'customers customer book address edit customer import vyapar party new order for customer',
            ['Customers', [
                'More → “Customers”. :: Every customer with their orders and address.',
                'Saved by themselves from orders. :: “✎ Edit customer” to change. Import from Vyapar in More.',
                '“+ New order for this customer”. :: Opens an order with their address.',
            ], ''],
            ['കസ്റ്റമർമാർ', [
                'More → “Customers”. :: എല്ലാ കസ്റ്റമറും, ഓർഡറും അഡ്രസും.',
                'ഓർഡറിൽ നിന്ന് തനിയെ സേവ് ആകും. :: മാറ്റാൻ “✎ Edit customer”. Vyapar-ൽ നിന്ന് ഇമ്പോർട്ട് More-ൽ.',
                '“+ New order for this customer”. :: അവരുടെ അഡ്രസോടെ ഓർഡർ തുറക്കും.',
            ], '']],
        ['backup', 'admin', 'admin', 'backup restore google drive download data safe lost recover zip',
            ['Backup', [
                'More → “Backup & restore” → “Back up now”. :: Orders, bills, stock, tasks, attendance and photos.',
                '“Connect Google Drive” for daily backups. :: The last 7 are kept.',
                'Restore only from these backups. :: A safety copy is made first. Type RESTORE to confirm.',
            ], ''],
            ['ബാക്കപ്പ്', [
                'More → “Backup & restore” → “Back up now”. :: ഓർഡർ, ബിൽ, സ്റ്റോക്ക്, ടാസ്ക്, അറ്റൻഡൻസ്, ഫോട്ടോ.',
                'ദിവസവും ബാക്കപ്പിന് “Connect Google Drive”. :: അവസാന 7 എണ്ണം സൂക്ഷിക്കും.',
                'ഈ ബാക്കപ്പിൽ നിന്ന് മാത്രം റീസ്റ്റോർ. :: ആദ്യം സേഫ്റ്റി കോപ്പി ഉണ്ടാക്കും. RESTORE ടൈപ്പ് ചെയ്യുക.',
            ], '']],
        ['settings', 'admin', 'admin', 'settings dispatch days sundays company name whatsapp message received days morning summary cron icon number',
            ['Settings', [
                'More → “Settings”. :: Admin only.',
                'Dispatch days, morning summary, received days. :: Also the hours for “orders waiting”.',
                'Label address and WhatsApp messages. :: For the 9 am summary on time, add the cron line shown there in hPanel.',
            ], ''],
            ['സെറ്റിംഗ്സ്', [
                'More → “Settings”. :: അഡ്മിന് മാത്രം.',
                'ഡിസ്പാച്ച് ദിവസം, രാവിലത്തെ സമ്മറി, received ദിവസം. :: “orders waiting” മണിക്കൂറും.',
                'ലേബൽ അഡ്രസ്, WhatsApp മെസേജ്. :: 9 മണി സമ്മറി കൃത്യമാകാൻ അവിടെ കാണുന്ന cron വരി hPanel-ൽ ചേർക്കുക.',
            ], '']],
        ['storage', 'admin', 'admin', 'free up space storage disk full images photos delete old',
            ['Free up space', [
                'More → “Free up space”. :: See where the space goes.',
                'Remove photos of shipped orders. :: Also photos of deleted orders and leftover files.',
                'Order details stay. :: Make a backup first if you may need the photos.',
            ], ''],
            ['സ്പേസ് ഒഴിവാക്കാൻ', [
                'More → “Free up space”. :: സ്പേസ് എവിടെ പോകുന്നു എന്ന് കാണാം.',
                'ഷിപ്പ് ചെയ്ത ഓർഡറുകളുടെ ഫോട്ടോ കളയാം. :: ഡിലീറ്റ് ചെയ്തവയുടെ ഫോട്ടോയും ബാക്കി ഫയലുകളും.',
                'ഓർഡർ വിവരം നിൽക്കും. :: ഫോട്ടോ വേണ്ടി വന്നേക്കാമെങ്കിൽ ആദ്യം ബാക്കപ്പ്.',
            ], '']],
        ['dashboard', 'admin', 'admin', 'dashboard report today sales delayed team numbers overview damaged stock low production fabric',
            ['Dashboard', [
                'Open “Dashboard”. :: In the top bar.',
                'Sales, dues, today’s work, delayed, damaged. :: Pick another day at the top to see that day.',
            ], ''],
            ['ഡാഷ്ബോർഡ്', [
                '“Dashboard” തുറക്കുക. :: മുകളിലെ ബാറിൽ.',
                'വിൽപ്പന, കിട്ടാനുള്ളത്, ഇന്നത്തെ ജോലി, വൈകിയവ, കേടായവ. :: മറ്റൊരു ദിവസം കാണാൻ മുകളിൽ തീയതി മാറ്റുക.',
            ], '']],
    ];
}

/** Topics this person may see (admins see everything). */
function sop_visible(array $u): array
{
    $admin = is_admin($u);
    $orders = $admin || cap('create', $u) || user_job($u) === 'production';
    $money = $admin || cap('billing', $u) || cap('stock', $u) || cap('accounts', $u);
    return array_values(array_filter(sop_topics(), fn($t) => match ($t[2]) {
        'admin' => $admin,
        'orders' => $orders,
        'money' => $money,
        default => true,
    }));
}
