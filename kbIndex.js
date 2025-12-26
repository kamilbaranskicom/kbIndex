document.querySelectorAll('th.sortable').forEach(th=>{
    th.addEventListener('click',()=>{
        const table = th.closest('table');
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const col = Array.from(th.parentNode.children).indexOf(th);
        const order = th.dataset.order==='asc'?'desc':'asc';
        th.dataset.order = order;

        rows.sort((a,b)=>{
            const aDir = a.dataset.isdir==='1'?1:0;
            const bDir = b.dataset.isdir==='1'?1:0;
            if(aDir!==bDir) return bDir-aDir;

            let aVal = a.children[col].innerText.trim();
            let bVal = b.children[col].innerText.trim();
            if(['size','mtime'].includes(th.dataset.sort)){
                aVal = parseFloat(aVal) || 0;
                bVal = parseFloat(bVal) || 0;
            }
            return (aVal>bVal?1:-1)*(order==='asc'?1:-1);
        });
        rows.forEach(r=>tbody.appendChild(r));
    });
});
