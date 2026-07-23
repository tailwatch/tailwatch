import Header from '../../Components/Header/Header'
import IpBasedProtection from './IpBasedProtection/IpBasedProtection'

const BruteForce = () => {
  return (
    <div>
      <Header title="Login Defender" />
      <div className='px-4 pt-4'>
        <IpBasedProtection />
      </div>
    </div>
  )
}

export default BruteForce
